<?php

namespace Database\Seeders;

use App\Models\Payroll\StatutoryRateSet;
use Illuminate\Database\Seeder;

/**
 * A starting set of Ghana statutory rates, left UNCONFIRMED: HR must check every figure against the
 * current GRA and SSNIT publications and confirm the set in Payroll → Settings before any pay run
 * uses it. Runs once (does nothing when any set exists).
 */
class StatutoryRatesSeeder extends Seeder
{
    public function run(): void
    {
        if (StatutoryRateSet::withTrashed()->exists()) {
            return;
        }

        StatutoryRateSet::create([
            'name'           => 'Ghana (starting set: verify before use)',
            'effective_from' => '2024-01-01',
            'notes'          => 'Seeded from the 2024 GRA monthly PAYE schedule and SSNIT rates. Check every figure, '
                . 'especially the maximum insurable earnings, which SSNIT revises each year.',
            'ssnit' => [
                'employee_rate'          => 5.5,
                'employer_rate'          => 13,
                'tier1_rate'             => 13.5,
                'tier2_rate'             => 5,
                'max_insurable_earnings' => null,
            ],
            // Monthly chargeable income: each band's width, then the rest.
            'paye_bands' => [
                ['limit' => 490, 'rate' => 0],
                ['limit' => 110, 'rate' => 5],
                ['limit' => 130, 'rate' => 10],
                ['limit' => 3166.67, 'rate' => 17.5],
                ['limit' => 16000, 'rate' => 25],
                ['limit' => 30520, 'rate' => 30],
                ['limit' => null, 'rate' => 35],
            ],
            'reliefs' => [
                ['code' => 'marriage', 'name' => 'Marriage / responsibility', 'annual_amount' => 1200, 'max_units' => null, 'percent_of_income' => null],
                ['code' => 'children', 'name' => "Children's education (per child)", 'annual_amount' => 600, 'max_units' => 3, 'percent_of_income' => null],
                ['code' => 'aged_dependant', 'name' => 'Aged dependant (per dependant)', 'annual_amount' => 1000, 'max_units' => 2, 'percent_of_income' => null],
                ['code' => 'old_age', 'name' => 'Old age (60 and over)', 'annual_amount' => 1500, 'max_units' => null, 'percent_of_income' => null],
                ['code' => 'disability', 'name' => 'Disability', 'annual_amount' => null, 'max_units' => null, 'percent_of_income' => 25],
                ['code' => 'professional_training', 'name' => 'Professional / vocational training', 'annual_amount' => 2000, 'max_units' => null, 'percent_of_income' => null],
            ],
            'tier3_relief_limit_percent' => 16.5,
            'overtime_tax' => ['annual_basic_threshold' => 18000, 'percent_of_basic' => 50, 'lower_rate' => 5, 'higher_rate' => 10],
            'bonus_tax'    => ['rate' => 5, 'percent_of_annual_basic' => 15],
        ]);
    }
}
