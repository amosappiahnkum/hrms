<?php

namespace Database\Seeders;

use App\Enums\Payroll\ComponentCalculation;
use App\Enums\Payroll\ComponentKind;
use App\Models\Payroll\PayComponent;
use Illuminate\Database\Seeder;

/** The built-in components (basic salary). Everything else is the organization's to define. */
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
    }
}
