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
    protected array $auditExclude = ['account_number', 'mobile_money_number'];

    protected $fillable = [
        'employee_id', 'effective_from', 'basic_salary', 'currency', 'payment_method', 'bank_name', 'bank_branch',
        'account_name', 'account_number', 'mobile_money_provider', 'mobile_money_number', 'tin', 'tier2_scheme',
        'tier3_scheme', 'tier3_percent', 'reliefs', 'tax_resident', 'overtime_eligible', 'notes', 'created_by',
    ];

    protected $casts = [
        'effective_from'      => 'date',
        'basic_salary'        => 'decimal:2',
        'account_number'      => 'encrypted',
        'mobile_money_number' => 'encrypted',
        'tier3_percent'       => 'decimal:2',
        'reliefs'             => 'array',
        'tax_resident'        => 'boolean',
        'overtime_eligible'   => 'boolean',
    ];

    /** The rules pay details must meet, wherever they're entered (the form or an import). */
    public static function rules(): array
    {
        return [
            'basic_salary'          => ['required', 'numeric', 'min:0'],
            'currency'              => ['nullable', \App\Support\Currencies::rule()],
            'payment_method'        => ['required', \Illuminate\Validation\Rule::in(array_keys(self::PAYMENT_METHODS))],
            'bank_name'             => ['nullable', 'required_if:payment_method,bank', 'string', 'max:255'],
            'bank_branch'           => ['nullable', 'string', 'max:255'],
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

    /** Payment details that don't apply to the payment method, cleared. */
    public static function withoutUnusedPayment(array $data): array
    {
        if (($data['payment_method'] ?? null) !== 'bank') {
            $data = array_merge($data, ['bank_name' => null, 'bank_branch' => null, 'account_name' => null, 'account_number' => null]);
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
