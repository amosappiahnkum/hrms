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

    protected $fillable = [
        'code', 'name', 'kind', 'calculation', 'rate', 'currency', 'unit', 'taxable', 'is_bonus', 'ssnit_applicable',
        'recurring', 'prorate', 'show_on_payslip', 'sort_order', 'account_code', 'active', 'is_system',
    ];

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
