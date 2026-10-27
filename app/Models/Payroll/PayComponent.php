<?php

namespace App\Models\Payroll;

use App\Enums\Payroll\ComponentCalculation;
use App\Enums\Payroll\ComponentKind;
use App\Models\AppModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A line a payslip can carry: basic, an allowance, an overtime type, a deduction… */
class PayComponent extends AppModel
{
    use SoftDeletes;

    public const BASIC = 'BASIC';
    public const LOAN_REPAYMENT = 'LOAN_REPAY';
    public const LOAN_DISBURSEMENT = 'LOAN_PAYOUT';
    public const ARREARS = 'ARREARS';
    /** Not paid: the days an employee worked in the period, for components that follow them. */
    public const DAYS_WORKED = 'DAYS_WORKED';

    /** prorate_basis: joiners/leavers by calendar days, or days worked ÷ working days. */
    public const PRORATE_EMPLOYMENT = 'employment';
    /** prorate_basis / quantity_source: follows the days worked. */
    public const FROM_DAYS_WORKED = 'days_worked';
    /** quantity_source: the quantity is entered (time input, pay run input). */
    public const QUANTITY_INPUT = 'input';

    protected $fillable = [
        'code', 'name', 'kind', 'calculation', 'rate', 'currency', 'unit', 'quantity_source', 'taxable', 'is_bonus', 'ssnit_applicable',
        'recurring', 'prorate', 'prorate_basis', 'show_on_payslip', 'sort_order', 'account_code', 'active', 'is_system',
    ];

    /** Whether any active component follows days worked (so days worked are collected). */
    public static function daysWorkedInUse(): bool
    {
        return self::where('active', true)
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('prorate', true)->where('prorate_basis', self::FROM_DAYS_WORKED))
                ->orWhere('quantity_source', self::FROM_DAYS_WORKED))
            ->exists();
    }

    protected $casts = [
        'kind'             => ComponentKind::class,
        'calculation'      => ComponentCalculation::class,
        'rate'             => 'decimal:4',
        'taxable'          => 'boolean',
        'is_bonus'         => 'boolean',
        'ssnit_applicable' => 'boolean',
        'recurring'        => 'boolean',
        'prorate'          => 'boolean',
        'show_on_payslip'  => 'boolean',
        'active'           => 'boolean',
        'is_system'        => 'boolean',
        'sort_order'       => 'integer',
    ];
}
