<?php

namespace Tests\Unit;

use App\Exceptions\UserFacingException;
use App\Models\Payroll\EmployeePayComponent;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\StatutoryRateSet;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The pay rules, checked against figures worked out by hand from the 2024 GRA bands and SSNIT rates. */
class PayrollCalculatorTest extends TestCase
{
    private function statutory(array $overrides = []): StatutoryRateSet
    {
        return new StatutoryRateSet($overrides + [
            'ssnit'      => ['employee_rate' => 5.5, 'employer_rate' => 13, 'tier1_rate' => 13.5, 'tier2_rate' => 5, 'max_insurable_earnings' => 61000],
            'paye_bands' => [
                ['limit' => 490, 'rate' => 0], ['limit' => 110, 'rate' => 5], ['limit' => 130, 'rate' => 10],
                ['limit' => 3166.67, 'rate' => 17.5], ['limit' => 16000, 'rate' => 25], ['limit' => 30520, 'rate' => 30], ['limit' => null, 'rate' => 35],
            ],
            'reliefs'    => [['code' => 'marriage', 'name' => 'Marriage', 'annual_amount' => 1200, 'max_units' => null, 'percent_of_income' => null]],
            'tier3_relief_limit_percent' => 16.5,
            'non_resident_rate' => 25,
            'overtime_tax' => ['annual_basic_threshold' => 18000, 'percent_of_basic' => 50, 'lower_rate' => 5, 'higher_rate' => 10],
            'bonus_tax'    => ['rate' => 5, 'percent_of_annual_basic' => 15],
        ]);
    }

    private function basic(): PayComponent
    {
        return new PayComponent(['code' => 'BASIC', 'name' => 'Basic salary', 'kind' => 'earning', 'calculation' => 'manual', 'taxable' => true, 'ssnit_applicable' => true, 'prorate' => true, 'show_on_payslip' => true, 'sort_order' => 1]);
    }

    private function calculator(array $settings = [], array $rates = [], string $month = '2026-06'): PayrollCalculator
    {
        $start = Carbon::parse("{$month}-01");

        return new PayrollCalculator(
            ['start' => $start, 'end' => $start->copy()->endOfMonth()->startOfDay()],
            $this->statutory(),
            $rates,
            $settings + ['base_currency' => 'GHS', 'working_days_per_month' => 22, 'hours_per_day' => 8, 'overtime_tax_method' => 'income'],
            $this->basic(),
        );
    }

    private function profile(array $attributes = []): EmployeePayProfile
    {
        return new EmployeePayProfile($attributes + ['basic_salary' => 5000, 'currency' => null, 'tax_resident' => true, 'reliefs' => []]);
    }

    private function given(PayComponent $component, ?float $amount = null): EmployeePayComponent
    {
        $given = new EmployeePayComponent(['amount' => $amount]);
        $given->setRelation('component', $component);

        return $given;
    }

    public function test_a_standard_month_ssnit_paye_and_net_pay(): void
    {
        $transport = new PayComponent(['code' => 'TRANSPORT', 'name' => 'Transport', 'kind' => 'earning', 'calculation' => 'fixed', 'rate' => 300, 'taxable' => true, 'ssnit_applicable' => false, 'prorate' => true]);

        $r = $this->calculator()->calculate($this->profile(['reliefs' => [['code' => 'marriage']]]), collect([$this->given($transport)]), [], null, null, true);

        // Gross 5,300; SSNIT on basic 5,000: 275 employee, 650 employer, Tier 1 675, Tier 2 250.
        // Taxable 5,300 − 275 − 100 (marriage relief, 1,200 ÷ 12) = 4,925 → PAYE 829.75.
        $this->assertSame(5300.0, $r['gross_pay']);
        $this->assertSame([275.0, 650.0, 675.0, 250.0], [$r['ssnit_employee'], $r['ssnit_employer'], $r['tier1'], $r['tier2']]);
        $this->assertSame(4925.0, $r['taxable_income']);
        $this->assertSame(829.75, $r['paye']);
        $this->assertSame(4195.25, $r['net_pay']);
        $this->assertSame(5950.0, $r['employer_cost']);
        $this->assertSame([], $r['warnings']);
        $this->assertSame(['BASIC', 'TRANSPORT', 'SSNIT', 'PAYE', 'SSNIT_ER'], array_column($r['lines'], 'code'));
    }

    public function test_ssnit_is_capped_at_the_maximum_insurable_earnings(): void
    {
        $r = $this->calculator()->calculate($this->profile(['basic_salary' => 80000]), collect(), [], null, null, true);

        $this->assertSame(round(61000 * 0.055, 2), $r['ssnit_employee']);
    }

    public function test_pay_in_another_currency_is_converted_at_the_years_rate(): void
    {
        $r = $this->calculator(rates: ['USD' => 15])->calculate($this->profile(['basic_salary' => 1000, 'currency' => 'USD']), collect(), [], null, null, true);

        $this->assertSame(15000.0, $r['basic_salary']);
        $this->assertSame(['USD', 1000.0, 15], [$r['lines'][0]['original_currency'], $r['lines'][0]['original_amount'], $r['lines'][0]['exchange_rate']]);

        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('no USD exchange rate');
        $this->calculator()->calculate($this->profile(['currency' => 'USD']), collect(), [], null, null, true);
    }

    public function test_joiners_are_paid_for_the_days_they_worked(): void
    {
        // June 2026 has 30 days; joined on the 16th: 15 days.
        $r = $this->calculator()->calculate($this->profile(), collect(), [], Carbon::parse('2026-06-16'), null, true);

        $this->assertSame(0.5, $r['proration']);
        $this->assertSame(2500.0, $r['basic_salary']);
    }

    public function test_non_residents_pay_a_flat_rate(): void
    {
        $r = $this->calculator()->calculate($this->profile(['tax_resident' => false]), collect(), [], null, null, true);

        // (5,000 − 275) × 25%
        $this->assertSame(1181.25, $r['paye']);
    }

    public function test_overtime_is_taxed_as_income_or_at_gra_junior_rates_by_setting(): void
    {
        $ot = new PayComponent(['code' => 'OT_WD', 'name' => 'Weekday overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 1.5, 'taxable' => true]);
        $inputs = [['component' => $ot, 'quantity' => 10, 'amount' => null, 'source' => 'overtime']];
        $profile = $this->profile(['basic_salary' => 1000]);

        // 10 h × 1.5 × (1,000 ÷ 22 ÷ 8) = 85.23
        $asIncome = $this->calculator()->calculate($profile, collect(), $inputs, null, null, true);
        $this->assertSame(85.23, $asIncome['lines'][1]['amount']);
        // Taxable 1,085.23 − 55 = 1,030.23 → PAYE 71.04
        $this->assertSame(71.04, $asIncome['paye']);

        // Junior staff (annual basic 12,000 ≤ 18,000): overtime apart at 5% (under 50% of basic) → 56.13 + 4.26
        $junior = $this->calculator(['overtime_tax_method' => 'gra_junior'])->calculate($profile, collect(), $inputs, null, null, true);
        $this->assertSame(60.39, $junior['paye']);
    }

    public function test_missing_ssnit_numbers_and_negative_net_pay_are_flagged(): void
    {
        $loan = new PayComponent(['code' => 'ADV', 'name' => 'Advance', 'kind' => 'deduction', 'calculation' => 'fixed', 'rate' => 9000, 'taxable' => false]);

        $r = $this->calculator()->calculate($this->profile(), collect([$this->given($loan)]), [], null, null, false);

        $this->assertContains('No SSNIT number.', $r['warnings']);
        $this->assertContains('Deductions exceed pay: net pay is negative.', $r['warnings']);
    }

    private function input(array $component, float $amount): array
    {
        return ['component' => new PayComponent($component + ['kind' => 'earning', 'calculation' => 'manual', 'taxable' => true, 'ssnit_applicable' => false]), 'quantity' => null, 'amount' => $amount, 'source' => 'input'];
    }

    public function test_a_bonus_is_taxed_at_the_bonus_rate_up_to_its_share_of_annual_basic(): void
    {
        $bonus = $this->input(['code' => 'BONUS', 'name' => 'Bonus', 'is_bonus' => true], 5000);

        // Allowance 15% of 60,000 = 9,000: all 5,000 at 5% (250), on top of 779.75 on the salary.
        $r = $this->calculator()->calculate($this->profile(), collect(), [$bonus], null, null, true);
        $this->assertSame([5000.0, 250.0, 1029.75], [$r['bonus_concession'], $r['bonus_tax'], $r['paye']]);
        $this->assertSame(4725.0, $r['taxable_income']);

        // 7,000 of the allowance used earlier in the year: 2,000 at 5%, 3,000 as income at 25%.
        $r = $this->calculator()->calculate($this->profile(), collect(), [$bonus], null, null, true, ['bonus_concession_ytd' => 7000]);
        $this->assertSame([2000.0, 100.0], [$r['bonus_concession'], $r['bonus_tax']]);
        $this->assertSame(round(779.75 + 3000 * 0.25 + 100, 2), $r['paye']);

        // All as income when the organization says so.
        $r = $this->calculator(['bonus_tax_method' => 'income'])->calculate($this->profile(), collect(), [$bonus], null, null, true);
        $this->assertSame(0.0, $r['bonus_tax']);
        $this->assertSame(9725.0, $r['taxable_income']);
    }

    public function test_an_off_cycle_run_pays_only_its_inputs_taxed_on_top_of_the_month(): void
    {
        $extra = $this->input(['code' => 'ONE_OFF', 'name' => 'One-off', 'ssnit_applicable' => true], 1000);

        // The regular run taxed 4,725 and used 5,000 of the SSNIT cap: 1,000 more is taxed at 25%.
        $r = $this->calculator()->calculate($this->profile(), collect(), [$extra], null, null, true, ['off_cycle' => true, 'taxable' => 4725, 'ssnit_base' => 5000]);
        $this->assertSame(['ONE_OFF', 'SSNIT', 'PAYE', 'SSNIT_ER'], array_column($r['lines'], 'code'));
        $this->assertSame([1000.0, 55.0], [$r['gross_pay'], $r['ssnit_employee']]);
        $this->assertSame(round((1000 - 55) * 0.25, 2), $r['paye']);

        // Near the SSNIT cap, only what's left of it.
        $r = $this->calculator()->calculate($this->profile(), collect(), [$extra], null, null, true, ['off_cycle' => true, 'taxable' => 4725, 'ssnit_base' => 60600]);
        $this->assertSame(round(400 * 0.055, 2), $r['ssnit_employee']);
    }
}
