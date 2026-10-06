<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use App\Models\SelfService\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An employee's pay for a run, as calculated (never recalculated once the run is approved). */
class Payslip extends AppModel
{
    protected $fillable = [
        'pay_run_id', 'employee_id', 'employee_snapshot', 'payment_snapshot', 'proration', 'basic_salary', 'gross_pay',
        'taxable_income', 'ssnit_base', 'ssnit_employee', 'ssnit_employer', 'tier1', 'tier2', 'tier3', 'paye', 'bonus_concession', 'bonus_tax', 'total_deductions',
        'net_pay', 'employer_cost', 'warnings',
    ];

    protected $casts = [
        'employee_snapshot' => 'array',
        'payment_snapshot'  => 'encrypted:array',
        'proration'         => 'decimal:4',
        'warnings'          => 'array',
    ];

    /** Payment details stay out of the audit log. */
    protected array $auditExclude = ['payment_snapshot'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayRun::class, 'pay_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
