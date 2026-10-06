<?php

namespace Database\Seeders;

use App\Enums\Payroll\ComponentCalculation;
use App\Enums\Payroll\ComponentKind;
use App\Models\Payroll\PayComponent;
use Illuminate\Database\Seeder;

/** The built-in components (basic salary, loan lines). Everything else is the organization's to define. */
class PayComponentsSeeder extends Seeder
{
    public function run(): void
    {
        PayComponent::withTrashed()->firstOrCreate(['code' => PayComponent::BASIC], [
            'name'             => 'Basic salary',
            'kind'             => ComponentKind::EARNING,
            // The amount comes from each employee's pay profile.
            'calculation'      => ComponentCalculation::MANUAL,
            'taxable'          => true,
            'ssnit_applicable' => true,
            'recurring'        => true,
            'prorate'          => true,
            'show_on_payslip'  => true,
            'sort_order'       => 1,
            'is_system'        => true,
        ]);

        // Loans: repayments deducted after tax, and loans paid out on a payslip (not income).
        PayComponent::withTrashed()->firstOrCreate(['code' => PayComponent::LOAN_REPAYMENT], [
            'name' => 'Loan repayment', 'kind' => ComponentKind::DEDUCTION, 'calculation' => ComponentCalculation::MANUAL,
            'taxable' => false, 'ssnit_applicable' => false, 'recurring' => false, 'prorate' => false,
            'show_on_payslip' => true, 'sort_order' => 90, 'is_system' => true,
        ]);
        // Back pay after a raise entered with an earlier date: taxed and subject to SSNIT like basic.
        PayComponent::withTrashed()->firstOrCreate(['code' => PayComponent::ARREARS], [
            'name' => 'Back pay', 'kind' => ComponentKind::EARNING, 'calculation' => ComponentCalculation::MANUAL,
            'taxable' => true, 'ssnit_applicable' => true, 'recurring' => false, 'prorate' => false,
            'show_on_payslip' => true, 'sort_order' => 5, 'is_system' => true,
        ]);
        PayComponent::withTrashed()->firstOrCreate(['code' => PayComponent::LOAN_DISBURSEMENT], [
            'name' => 'Loan paid out', 'kind' => ComponentKind::EARNING, 'calculation' => ComponentCalculation::MANUAL,
            'taxable' => false, 'ssnit_applicable' => false, 'recurring' => false, 'prorate' => false,
            'show_on_payslip' => true, 'sort_order' => 90, 'is_system' => true,
        ]);
    }
}
