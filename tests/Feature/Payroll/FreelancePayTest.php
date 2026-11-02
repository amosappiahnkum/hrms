<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayComponent;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\Payslip;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Freelancers on day rates: their own rate per day, no basic or SSNIT, taxed as income or by withholding. */
class FreelancePayTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private $freelancer;
    private PayComponent $working;
    private PayComponent $standby;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.time_inputs'])->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        app(SettingService::class)->set('payroll.time_inputs_require_approval', false);
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'view-payroll', 'enter-time-inputs']);
        $this->freelancer = $this->userWithRole('staff')->employee;
        $this->freelancer->update(['job_type' => 'freelance', 'ssnit_number' => null]);
        EmployeePayProfile::create(['employee_id' => $this->freelancer->id, 'effective_from' => '2026-01-01', 'basic_salary' => 0, 'payment_method' => 'cash']);

        $this->working = PayComponent::create(['code' => 'WORKING_DAY', 'name' => 'Working day', 'kind' => 'earning', 'calculation' => 'rate_per_unit', 'rate' => 300, 'unit' => 'day', 'recurring' => true, 'taxable' => true]);
        $this->standby = PayComponent::create(['code' => 'STANDBY_DAY', 'name' => 'Standby day', 'kind' => 'earning', 'calculation' => 'rate_per_unit', 'rate' => 200, 'unit' => 'day', 'recurring' => true, 'taxable' => true]);
        // Their own working-day rate; the standby day at the standard rate.
        EmployeePayComponent::create(['employee_id' => $this->freelancer->id, 'pay_component_id' => $this->working->id, 'amount' => 450, 'effective_from' => '2026-01-01']);
        EmployeePayComponent::create(['employee_id' => $this->freelancer->id, 'pay_component_id' => $this->standby->id, 'effective_from' => '2026-01-01']);
        Sanctum::actingAs($this->hr);
    }

    private function days(PayComponent $component, float $days): void
    {
        $this->putJson('/api/v1/payroll/time-inputs', [
            'employee_uuid' => $this->freelancer->uuid, 'pay_component_uuid' => $component->uuid, 'year' => 2026, 'month' => 9, 'quantity' => $days,
        ])->assertOk();
    }

    private function calculate()
    {
        $run = PayRun::where(['year' => 2026, 'month' => 9])->first()
            ?? PayRun::where('uuid', $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->json('data.uuid'))->first();

        return $this->postJson("/api/v1/payroll/runs/{$run->uuid}/calculate");
    }

    private function slip(): Payslip
    {
        return Payslip::where('employee_id', $this->freelancer->id)->with('lines')->sole();
    }

    public function test_days_are_paid_at_the_freelancers_own_rate_without_basic_or_ssnit(): void
    {
        $this->days($this->working, 13);
        $this->days($this->standby, 2);
        $this->calculate()->assertOk();

        $slip = $this->slip();
        $lines = $slip->lines->keyBy('code');
        $this->assertSame([5850.0, 450.0], [(float) $lines['WORKING_DAY']->amount, (float) $lines['WORKING_DAY']->rate]); // 13 × 450
        $this->assertSame(400.0, (float) $lines['STANDBY_DAY']->amount);                                                 // 2 × 200
        $this->assertFalse((bool) $lines['BASIC']->show_on_payslip);
        $this->assertSame([0.0, 0.0], [(float) $slip->ssnit_employee, (float) $slip->ssnit_employer]);
        $this->assertNotContains('No SSNIT number.', $slip->warnings);
        $this->assertGreaterThan(0, (float) $slip->paye);   // taxed as income by default
        $this->assertSame(0.0, (float) $slip->withholding_tax);
    }

    public function test_no_days_means_nothing_from_the_day_rates(): void
    {
        $this->calculate()->assertOk();

        $this->assertNull($this->slip()->lines->firstWhere('code', 'WORKING_DAY'));
        $this->assertSame(0.0, (float) $this->slip()->gross_pay);
    }

    public function test_freelancers_can_be_taxed_by_a_flat_withholding_rate(): void
    {
        app(SettingService::class)->set('payroll.freelance_tax', 'withholding');
        $this->days($this->working, 13);
        $this->days($this->standby, 2);

        // The rate must be set on the statutory rates first.
        $this->calculate()->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'withholding rate for freelancers'));
        StatutoryRateSet::query()->update(['freelance_withholding_rate' => 7.5]);
        $this->calculate()->assertOk()->assertJsonPath('data.totals.withholding_tax', 468.75);

        $slip = $this->slip();
        $this->assertSame([0.0, 468.75], [(float) $slip->paye, (float) $slip->withholding_tax]); // 6,250 × 7.5%
        $this->assertSame('Withholding tax', $slip->lines->firstWhere('code', 'WHT')->name);
        $this->assertNull($slip->lines->firstWhere('code', 'PAYE'));
        $this->assertSame(468.75, (float) $slip->total_deductions);
        $this->assertSame(5781.25, (float) $slip->net_pay);
    }

    public function test_staff_are_unchanged_by_the_freelance_rules(): void
    {
        $staff = $this->userWithRole('staff')->employee;
        $staff->update(['ssnit_number' => 'C1']);
        EmployeePayProfile::create(['employee_id' => $staff->id, 'effective_from' => '2026-01-01', 'basic_salary' => 5000, 'payment_method' => 'cash']);
        StatutoryRateSet::query()->update(['freelance_withholding_rate' => 7.5]);
        app(SettingService::class)->set('payroll.freelance_tax', 'withholding');
        $this->calculate()->assertOk();

        $slip = Payslip::where('employee_id', $staff->id)->sole();
        $this->assertSame(275.0, (float) $slip->ssnit_employee);
        $this->assertSame(785.75, (float) $slip->paye); // 2026 bands on 4,725 taxable
        $this->assertSame(0.0, (float) $slip->withholding_tax);
    }
}
