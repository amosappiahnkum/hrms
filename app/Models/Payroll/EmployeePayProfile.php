<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use App\Models\SelfService\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** What an employee is paid and how, from a date. */
class EmployeePayProfile extends AppModel
{
    use SoftDeletes;

    public const PAYMENT_METHODS = ['bank' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'cash' => 'Cash', 'cheque' => 'Cheque'];

    /** Payment numbers stay out of the audit log (other changes are recorded). */
    protected array $auditExclude = ['account_number', 'mobile_money_number', 'account_fingerprint', 'mobile_money_fingerprint'];

    protected $hidden = ['account_fingerprint', 'mobile_money_fingerprint'];

    protected $fillable = [
        'employee_id', 'effective_from', 'basic_salary', 'currency', 'fixed_exchange_rate', 'payment_method', 'bank_id', 'bank_name', 'bank_branch_id', 'bank_branch',
        'account_name', 'account_number', 'mobile_money_provider', 'mobile_money_number', 'tin', 'tier2_scheme',
        'tier3_scheme', 'tier3_percent', 'reliefs', 'tax_resident', 'overtime_eligible', 'notes', 'created_by',
    ];

    protected $casts = [
        'effective_from'      => 'date',
        'basic_salary'        => 'decimal:2',
        'fixed_exchange_rate' => 'decimal:6',
        'account_number'      => 'encrypted',
        'mobile_money_number' => 'encrypted',
        'tier3_percent'       => 'decimal:2',
        'reliefs'             => 'array',
        'tax_resident'        => 'boolean',
        'overtime_eligible'   => 'boolean',
    ];

    protected static function booted(): void
    {
        // Kept in step with the (encrypted) numbers, for duplicate checks.
        static::saving(function (self $p) {
            $p->account_fingerprint = self::fingerprint($p->account_number, 'bank');
            $p->mobile_money_fingerprint = self::fingerprint($p->mobile_money_number, 'mobile_money');
        });
    }

    /**
     * A keyed hash of a payment number, the same however it was typed: a bank account in capitals without spaces or
     * dashes; a mobile number as its local 10 digits (+233 24… and 024… match). Null when there's no number.
     */
    public static function fingerprint(?string $number, string $kind): ?string
    {
        $normal = $kind === 'mobile_money'
            ? preg_replace('/^(?:00)?233(?=\d{9}$)/', '0', preg_replace('/\D/', '', (string) $number))
            : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $number));
        if ($normal === '') {
            return null;
        }
        $key = config('kazi360.payment_fingerprint_key') ?: config('app.key');

        return hash_hmac('sha256', "{$kind}:{$normal}", (string) $key);
    }

    /**
     * Another employee whose current (or upcoming) pay details use this account (at the same bank) or mobile money
     * number; null when nobody else does. Details replaced since don't count. `$employeeIds`: whose details to
     * leave out (the employee, or everyone in an import, whose new details are checked against each other).
     */
    public static function sharedWith(array $data, int|array $employeeIds): ?self
    {
        $method = $data['payment_method'] ?? null;
        $fingerprint = match ($method) {
            'bank'         => self::fingerprint($data['account_number'] ?? null, 'bank'),
            'mobile_money' => self::fingerprint($data['mobile_money_number'] ?? null, 'mobile_money'),
            default        => null,
        };
        if (!$fingerprint) {
            return null;
        }
        $today = now()->toDateString();
        $current = self::inForceOn($today)->pluck('id')->flip();

        return self::query()
            ->whereNotIn('employee_id', (array) $employeeIds)
            ->where('payment_method', $method)
            ->where($method === 'bank' ? 'account_fingerprint' : 'mobile_money_fingerprint', $fingerprint)
            ->when($method === 'bank', fn ($q) => $q->where('bank_id', $data['bank_id'] ?? null))
            ->with('employee')->get()
            ->first(fn (self $p) => $current->has($p->id) || $p->effective_from->toDateString() > $today);
    }

    /** "This account is already used for …" when another employee has it (see sharedWith()); null otherwise. */
    public static function sharedProblem(array $data, int|array $employeeIds): ?string
    {
        $other = self::sharedWith($data, $employeeIds);
        if (!$other) {
            return null;
        }
        $who = trim(preg_replace('/\s+/', ' ', (string) $other->employee?->name)) . ($other->employee?->staff_id ? " ({$other->employee->staff_id})" : '');

        return self::sharedMessage($data['payment_method'], $who);
    }

    public static function sharedMessage(string $method, string $who): string
    {
        return ($method === 'bank' ? 'This account number' : 'This mobile money number') . " is already used for {$who}. Each employee needs their own.";
    }

    /** Which account details pay into, to compare (method, bank, fingerprint); null when not by bank or mobile money. */
    public static function paymentKey(array $data): ?string
    {
        return match ($data['payment_method'] ?? null) {
            'bank'         => ($f = self::fingerprint($data['account_number'] ?? null, 'bank')) ? 'bank:' . ($data['bank_id'] ?? '') . ":{$f}" : null,
            'mobile_money' => ($f = self::fingerprint($data['mobile_money_number'] ?? null, 'mobile_money')) ? "mobile_money:{$f}" : null,
            default        => null,
        };
    }

    /** The rules pay details must meet, wherever they're entered (the form or an import). */
    public static function rules(): array
    {
        return [
            'basic_salary'          => ['required', 'numeric', 'min:0'],
            'currency'              => ['nullable', \App\Support\Currencies::rule()],
            'fixed_exchange_rate'   => ['nullable', 'numeric', 'gt:0'],
            'payment_method'        => ['required', \Illuminate\Validation\Rule::in(array_keys(self::PAYMENT_METHODS))],
            // A bank from the banks list (its name is kept as bank_name: see withBank()).
            'bank_uuid'             => ['nullable', 'required_if:payment_method,bank', 'uuid'],
            // A branch of the chosen bank (its name is kept as bank_branch: see withBank()).
            'bank_branch_uuid'      => ['nullable', 'uuid'],
            'account_name'          => ['nullable', 'string', 'max:255'],
            'account_number'        => ['nullable', 'required_if:payment_method,bank', 'string', 'max:50'],
            'mobile_money_provider' => ['nullable', 'required_if:payment_method,mobile_money', 'string', 'max:50'],
            'mobile_money_number'   => ['nullable', 'required_if:payment_method,mobile_money', 'string', 'max:20'],
            'tin'                   => ['nullable', 'string', 'max:30'],
            'ssnit_number'          => ['sometimes', 'nullable', 'string', 'max:30'],
            'tier2_scheme'          => ['nullable', 'string', 'max:255'],
            'tier3_scheme'          => ['nullable', 'string', 'max:255'],
            'tier3_percent'         => ['nullable', 'numeric', 'between:0,100'],
            'tax_resident'          => ['boolean'],
            'overtime_eligible'     => ['nullable', 'boolean'],
        ];
    }

    /**
     * The rate that converts this basic salary into the base currency on a date: the employee's fixed (contract)
     * rate, else the basic rate when the organization uses one (setting `payroll.separate_basic_rate`), else the
     * standard rate. 1 in the base currency; null when no rate is in force.
     */
    public function basicRateOn(\Illuminate\Support\Carbon $on): ?float
    {
        $base = strtoupper((string) setting('payroll.base_currency', 'GHS'));
        if (!$this->currency || strtoupper($this->currency) === $base) {
            return 1.0;
        }
        if ($this->fixed_exchange_rate !== null) {
            return (float) $this->fixed_exchange_rate;
        }

        return ExchangeRate::for($this->currency, $on, setting('payroll.separate_basic_rate', false) ? ExchangeRate::BASIC : ExchangeRate::STANDARD);
    }

    /**
     * The chosen bank and branch (bank_uuid, bank_branch_uuid: active, the branch one of the bank's) as stored: their
     * ids, and their names as they are now (bank_name, bank_branch: kept for display and payslips).
     */
    public static function withBank(array $data): array
    {
        if (!array_key_exists('bank_uuid', $data)) {
            return $data;
        }
        $uuid = $data['bank_uuid'];
        $branchUuid = $data['bank_branch_uuid'] ?? null;
        unset($data['bank_uuid'], $data['bank_branch_uuid']);
        $bank = $uuid ? Bank::where('uuid', $uuid)->where('active', true)->first() : null;
        if ($uuid && !$bank) {
            throw \Illuminate\Validation\ValidationException::withMessages(['bank_uuid' => 'Choose a bank from the list.']);
        }
        $branch = $branchUuid && $bank ? BankBranch::where('uuid', $branchUuid)->where('bank_id', $bank->id)->where('active', true)->first() : null;
        if ($branchUuid && !$branch) {
            throw \Illuminate\Validation\ValidationException::withMessages(['bank_branch_uuid' => 'Choose a branch of this bank from the list.']);
        }

        return $data + ['bank_id' => $bank?->id, 'bank_name' => $bank?->name, 'bank_branch_id' => $branch?->id, 'bank_branch' => $branch?->name];
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BankBranch::class, 'bank_branch_id')->withTrashed();
    }

    public function bank(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Bank::class)->withTrashed();
    }

    /** Payment details that don't apply to the payment method, cleared. */
    public static function withoutUnusedPayment(array $data): array
    {
        if (($data['payment_method'] ?? null) !== 'bank') {
            $data = array_merge($data, ['bank_id' => null, 'bank_name' => null, 'bank_branch_id' => null, 'bank_branch' => null, 'account_name' => null, 'account_number' => null]);
        }
        if (($data['payment_method'] ?? null) !== 'mobile_money') {
            $data = array_merge($data, ['mobile_money_provider' => null, 'mobile_money_number' => null]);
        }

        return $data;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The profile in force on a date for each employee: the latest from on or before it. */
    public function scopeInForceOn(Builder $query, \DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)->whereNotExists(fn ($q) => $q
            ->from('employee_pay_profiles as later')
            ->whereColumn('later.employee_id', 'employee_pay_profiles.employee_id')
            ->whereDate('later.effective_from', '<=', $date)
            ->whereColumn('later.effective_from', '>', 'employee_pay_profiles.effective_from')
            ->whereNull('later.deleted_at'));
    }

    /** What's missing for this employee to be paid. */
    public function gaps(?string $ssnitNumber, ?string $ghanaCard): array
    {
        return array_values(array_filter([
            $this->payment_method === 'bank' && (!$this->bank_name || !$this->account_number) ? 'Bank details' : null,
            $this->payment_method === 'mobile_money' && !$this->mobile_money_number ? 'Mobile money number' : null,
            !$ssnitNumber ? 'SSNIT number' : null,
            !$this->tin && !$ghanaCard ? 'TIN / Ghana Card' : null,
        ]));
    }
}
