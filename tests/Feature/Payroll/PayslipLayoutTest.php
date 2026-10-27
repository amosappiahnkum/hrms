<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayComponent;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\ExchangeRate;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\Payslip;
use App\Models\Payroll\StatutoryRateSet;
use App\Services\Payroll\PayslipDocument;
use App\Services\Payroll\PayslipPresenter;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** The payslip as Apave's form lays it out: header, fixed lines at "–", exchange rates, staff cost, payment details. */
class PayslipLayoutTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private $employee;
    private string $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        $settings = app(SettingService::class);
        $settings->refreshCache();
        $settings->set('payroll.period_start_day', 21);
        $settings->set('payroll.exchange_rate_day', 22);
        $settings->set('payroll.separate_basic_rate', true);
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['prepare-payroll', 'configure-payroll', 'view-payroll']);
        Sanctum::actingAs($hr);

        $this->employee = $this->userWithRole('staff')->employee;
        $this->employee->update(['staff_id' => 'AI24016', 'ssnit_number' => 'B128910090013']);
        $this->employee->jobDetail()->updateOrCreate([], ['joined_date' => '2024-03-04']);
        EmployeePayProfile::create([
            'employee_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'basic_salary' => 1000, 'currency' => 'USD',
            'payment_method' => 'bank', 'bank_id' => $this->bank('Universal Merchant Bank')->id, 'bank_name' => 'Universal Merchant Bank', 'bank_branch' => 'Takoradi', 'account_number' => '51300487972',
        ]);
        $offshore = PayComponent::create(['code' => 'OFFSHORE_ALW', 'name' => 'Offshore allowance', 'kind' => 'earning', 'calculation' => 'fixed', 'rate' => 100, 'currency' => 'USD', 'recurring' => true, 'prorate' => false, 'taxable' => true, 'sort_order' => 50]);
        EmployeePayComponent::create(['employee_id' => $this->employee->id, 'pay_component_id' => $offshore->id, 'effective_from' => '2026-01-01']);
        PayComponent::create(['code' => 'BONUS', 'name' => 'Bonus', 'kind' => 'earning', 'calculation' => 'manual', 'is_bonus' => true, 'taxable' => true, 'sort_order' => 30]);
        ExchangeRate::create(['currency' => 'USD', 'rate_type' => 'standard', 'effective_date' => '2026-09-22', 'rate' => 11.3933]);
        ExchangeRate::create(['currency' => 'USD', 'rate_type' => 'basic', 'effective_date' => '2026-01-01', 'rate' => 13]);

        // Always printed, at "–" when there's nothing.
        $settings->set('payroll.payslip_fixed_lines', ['BONUS', 'TIER3', 'LOAN_REPAY']);

        $this->run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$this->run}/calculate")->assertOk();
    }

    private function payslip(): Payslip
    {
        return PayRun::where('uuid', $this->run)->first()->payslips()->sole();
    }

    public function test_the_payslip_carries_the_forms_header_and_fixed_lines(): void
    {
        $html = app(PayslipDocument::class)->html($this->payslip());

        foreach ([
            'AI24016', 'B128910090013',
            'Period start date', '21/08/2026', '20/09/2026', '30/09/2026', // period and pay date
            'Engagement date', '04/03/2024',
            'Cost centre', $this->employee->department->name,
            'Exchange rate: basic salary', 'USD 1 to GHS 13.00', 'Exchange rate: allowances', 'USD 1 to GHS 11.3933',
            'Exchange rate date', '22/09/2026',
            'Staff cost', 'Net salary', 'Take home pay',
            'Universal Merchant Bank', 'Takoradi', '•••• 7972',
        ] as $text) {
            $this->assertStringContainsString(e($text), $html, "Missing: {$text}");
        }
        $this->assertStringNotContainsString('51300487972', $html);

        // Lines it doesn't have but always prints, in payslip order.
        $presented = app(PayslipPresenter::class)->present($this->payslip());
        $this->assertSame(['BASIC', 'BONUS', 'OFFSHORE_ALW'], array_column($presented['earnings'], 'code'));
        $this->assertNull($presented['earnings'][1]['amount']);
        $this->assertSame(['LOAN_REPAY', 'SSNIT', 'TIER3', 'PAYE'], array_column($presented['deductions'], 'code'));
        $this->assertNull($presented['deductions'][2]['amount']);
    }

    public function test_staff_cost_is_gross_pay_and_the_employers_contributions(): void
    {
        $slip = $this->payslip();
        $totals = app(PayslipPresenter::class)->present($slip)['totals'];

        // 1,000 × 13 + 100 × 11.3933
        $this->assertSame(14139.33, $totals['gross_pay']);
        $this->assertSame(round($totals['gross_pay'] + (float) $slip->ssnit_employer, 2), $totals['staff_cost']);
        $this->assertSame((float) $slip->ssnit_employer, $totals['employer_contributions']);
    }

    public function test_the_account_number_can_be_printed_in_full(): void
    {
        app(SettingService::class)->set('payroll.payslip_account_number', 'full');

        $this->assertStringContainsString('51300487972', app(PayslipDocument::class)->html($this->payslip()));
    }

    public function test_the_payslip_api_returns_the_header_and_totals(): void
    {
        $slip = $this->payslip();

        $this->getJson("/api/v1/payroll/runs/{$this->run}/payslips/{$slip->uuid}")->assertOk()
            ->assertJsonPath('data.header.engagement_date', '2024-03-04')
            ->assertJsonPath('data.header.period_start', '2026-08-21')
            ->assertJsonPath('data.header.exchange_rates.0.applies_to', 'basic')
            ->assertJsonPath('data.header.exchange_rates.1.date', '2026-09-22')
            ->assertJsonPath('data.header.payment.account', '•••• 7972')
            ->assertJsonPath('data.totals.staff_cost', (float) $slip->employer_cost);
    }

    /** A bank on the banks list (made the first time). */
    private function bank(string $name): \App\Models\Payroll\Bank
    {
        return \App\Models\Payroll\Bank::firstOrCreate(['name' => $name], ['active' => true]);
    }
}
