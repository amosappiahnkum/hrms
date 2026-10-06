<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** How a payment file is laid out for a bank (or mobile money). */
class PaymentFileLayout extends AppModel
{
    use SoftDeletes;

    /** field => label: what a column can hold. */
    public const FIELDS = [
        'staff_id'              => 'Staff ID',
        'name'                  => 'Employee name',
        'account_name'          => 'Account name',
        'account_number'        => 'Account number',
        'bank_name'             => 'Bank',
        'bank_branch'           => 'Branch',
        'mobile_money_provider' => 'Mobile money provider',
        'mobile_money_number'   => 'Mobile money number',
        'amount'                => 'Amount (net pay)',
        'currency'              => 'Currency',
        'narration'             => 'Narration (e.g. "Salary June 2026")',
        'pay_date'              => 'Pay date',
    ];

    protected $fillable = ['name', 'payment_method', 'bank_name', 'format', 'delimiter', 'include_header', 'columns'];

    protected $casts = ['include_header' => 'boolean', 'columns' => 'array'];
}
