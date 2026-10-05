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
