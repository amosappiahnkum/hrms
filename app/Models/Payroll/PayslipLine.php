<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;

/** One line of a payslip, in the base currency (with its original amount and currency when converted). */
class PayslipLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity'         => 'decimal:2',
        'rate'             => 'decimal:4',
        'original_amount'  => 'decimal:2',
        'exchange_rate'    => 'decimal:6',
        'amount'           => 'decimal:2',
        'taxable'          => 'boolean',
        'ssnit_applicable' => 'boolean',
        'show_on_payslip'  => 'boolean',
    ];
}
