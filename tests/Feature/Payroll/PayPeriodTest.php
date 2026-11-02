<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Holiday;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\User;
use App\Services\Payroll\PayPeriod;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PayPeriodTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.overtime'])->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['prepare-payroll', 'approve-payroll', 'configure-payroll', 'view-payroll', 'view-overtime']);
        Sanctum::actingAs($this->hr);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function startOn(int $day): void
    {
        app(SettingService::class)->set('payroll.period_start_day', $day);
    }

    public function test_a_period_is_the_calendar_month_or_runs_from_the_start_day(): void
    {
        $june = PayPeriod::forMonth(2026, 6, 1);
        $this->assertSame(['2026-06-01', '2026-06-30'], [$june->start->toDateString(), $june->end->toDateString()]);
        $this->assertSame('June 2026', $june->label());

        $september = PayPeriod::forMonth(2026, 9, 21);
        $this->assertSame(['2026-08-21', '2026-09-20'], [$september->start->toDateString(), $september->end->toDateString()]);
        $this->assertSame('September 2026 (21 Aug – 20 Sep)', $september->label());

        // Across the year end, and around February.
        $january = PayPeriod::forMonth(2027, 1, 21);
        $this->assertSame(['2026-12-21', '2027-01-20'], [$january->start->toDateString(), $january->end->toDateString()]);
        $march = PayPeriod::forMonth(2026, 3, 21);
        $this->assertSame(['2026-02-21', '2026-03-20'], [$march->start->toDateString(), $march->end->toDateString()]);
    }

    public function test_a_date_belongs_to_the_period_that_contains_it(): void
    {
        $this->assertSame(8, PayPeriod::containing(Carbon::parse('2026-08-20'), 21)->month);
        $this->assertSame(9, PayPeriod::containing(Carbon::parse('2026-08-21'), 21)->month);
        $nextYear = PayPeriod::containing(Carbon::parse('2026-12-25'), 21);
        $this->assertSame([2027, 1], [$nextYear->year, $nextYear->month]);
        $this->assertSame(8, PayPeriod::containing(Carbon::parse('2026-08-31'), 1)->month);
    }

    public function test_working_days_leave_out_weekends_and_public_holidays(): void
    {
        // The sample payslip's period: 21 Aug – 20 Sep 2026.
        $this->assertSame(21, PayPeriod::forMonth(2026, 9, 21)->workingDays());

        Holiday::create(['description' => 'A weekday holiday', 'start_date' => '2026-09-01', 'end_date' => '2026-09-01']);
        Holiday::create(['description' => 'A weekend holiday', 'start_date' => '2026-09-05', 'end_date' => '2026-09-05']);
        Holiday::create(['description' => 'Outside the period', 'start_date' => '2026-09-21', 'end_date' => '2026-09-21']);
        $this->assertSame(20, PayPeriod::forMonth(2026, 9, 21)->workingDays());
    }

    public function test_a_run_covers_the_period_and_is_paid_on_the_pay_day(): void
    {
        $this->startOn(21);

        $this->getJson('/api/v1/payroll/runs/period?year=2026&month=9')->assertOk()
            ->assertJsonPath('data.period_start', '2026-08-21')
            ->assertJsonPath('data.period_end', '2026-09-20')
            ->assertJsonPath('data.working_days', 21)
            ->assertJsonPath('data.pay_date', '2026-09-30');

        $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->assertCreated()
            ->assertJsonPath('data.name', 'Payroll · September 2026')
            ->assertJsonPath('data.period_start', '2026-08-21')
            ->assertJsonPath('data.period_end', '2026-09-20')
            ->assertJsonPath('data.pay_date', '2026-09-30');

        app(SettingService::class)->set('payroll.pay_day', 25);
        $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 10])->assertCreated()
            ->assertJsonPath('data.period_start', '2026-09-21')
            ->assertJsonPath('data.pay_date', '2026-10-25');
    }

    public function test_a_joiner_is_paid_for_their_share_of_the_period(): void
    {
        $this->startOn(21);
        $staff = $this->userWithRole('staff');
        $staff->employee->update(['ssnit_number' => 'C1']);
        $staff->employee->jobDetail()->updateOrCreate([], ['joined_date' => '2026-09-01']);
        EmployeePayProfile::create(['employee_id' => $staff->employee_id, 'effective_from' => '2026-01-01', 'basic_salary' => 3100, 'payment_method' => 'cash']);

        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk();

        // 1 – 20 Sep of a 31-day period (21 Aug – 20 Sep).
        $slip = PayRun::where('uuid', $run)->first()->payslips()->sole();
        $this->assertEqualsWithDelta(20 / 31, (float) $slip->proration, 0.0001);
        $this->assertSame(2000.0, (float) $slip->basic_salary);
    }

    public function test_the_monthly_overtime_limit_counts_the_pay_period(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        $this->startOn(21);
        app(SettingService::class)->set('payroll.overtime_max_hours_per_month', 10);
        $staff = $this->userWithRole('staff');
        $staff->employee->update(['job_type' => 'full_time']);
        EmployeePayProfile::create(['employee_id' => $staff->employee_id, 'effective_from' => '2026-01-01', 'basic_salary' => 4400, 'payment_method' => 'cash']);
        $component = PayComponent::create(['code' => 'OT_WD', 'name' => 'Weekday overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 1.25, 'taxable' => true]);
        $this->postJson('/api/v1/payroll/overtime-types', ['name' => 'Weekday', 'pay_component_uuid' => $component->uuid, 'applies_on' => 'any'])->assertCreated();

        Sanctum::actingAs($staff);
        $request = fn (string $date, float $hours) => $this->postJson('/api/v1/payroll/my-overtime', ['work_date' => $date, 'hours' => $hours, 'reason' => 'Shutdown']);

        // 20 Aug is in the August period; 21 and 24 Aug are in September's.
        $request('2026-08-20', 8)->assertCreated();
        $request('2026-08-21', 8)->assertCreated();
        $request('2026-08-24', 3)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already requested for September 2026 (21 Aug – 20 Sep)'));
        $request('2026-08-24', 2)->assertCreated();
    }
}
