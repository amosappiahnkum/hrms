<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayComponent;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunInput;
use App\Models\Payroll\Payslip;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Allowances that follow days worked (Apave: transport prorated by days worked, lunch per day), and overtime's hourly base. */
class DaysWorkedTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.time_inputs'])->update(['value' => true]);
        $settings = app(SettingService::class);
        $settings->refreshCache();
        // 21 Aug – 20 Sep 2026: 21 working days.
        $settings->set('payroll.period_start_day', 21);
        $settings->set('payroll.time_inputs_require_approval', false);
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'enter-time-inputs', 'prepare-payroll', 'view-payroll']);
        $this->employee = $this->userWithRole('staff')->employee;
        $this->employee->update(['staff_id' => 'AI001', 'ssnit_number' => 'C1']);
        EmployeePayProfile::create(['employee_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'basic_salary' => 3360, 'payment_method' => 'cash']);
        Sanctum::actingAs($this->hr);
    }

    /** Transport (GHS 1,500 shared by days worked) and lunch (GHS 70 per day worked), given to the employee. */
    private function allowances(): array
    {
        $transport = $this->postJson('/api/v1/payroll/pay-components', [
            'name' => 'Transport allowance', 'kind' => 'earning', 'calculation' => 'fixed', 'rate' => 1500,
            'recurring' => true, 'prorate' => true, 'prorate_basis' => 'days_worked', 'taxable' => true,
        ])->assertCreated()->assertJsonPath('data.prorate_basis', 'days_worked')->json('data.uuid');
        $lunch = $this->postJson('/api/v1/payroll/pay-components', [
            'name' => 'Lunch allowance', 'kind' => 'earning', 'calculation' => 'rate_per_unit', 'rate' => 70, 'unit' => 'day',
            'quantity_source' => 'days_worked', 'recurring' => false, 'taxable' => true,
        ])->assertCreated()
            ->assertJsonPath('data.quantity_source', 'days_worked')
            // Paid to the employees it's given to, so always a standing item.
            ->assertJsonPath('data.recurring', true)->json('data.uuid');

        foreach ([$transport, $lunch] as $uuid) {
            EmployeePayComponent::create(['employee_id' => $this->employee->id, 'pay_component_id' => PayComponent::where('uuid', $uuid)->value('id'), 'effective_from' => '2026-01-01']);
        }

        return [$transport, $lunch];
    }

    private function daysWorked(float $days)
    {
        return $this->putJson('/api/v1/payroll/time-inputs', [
            'employee_uuid' => $this->employee->uuid, 'pay_component_uuid' => PayComponent::where('code', PayComponent::DAYS_WORKED)->value('uuid'),
            'year' => 2026, 'month' => 9, 'quantity' => $days,
        ])->assertOk();
    }

    private function calculate(): Payslip
    {
        $run = PayRun::where(['year' => 2026, 'month' => 9])->first()
            ?? PayRun::where('uuid', $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->assertCreated()->json('data.uuid'))->first();
        $this->postJson("/api/v1/payroll/runs/{$run->uuid}/calculate")->assertOk();

        return $run->payslips()->with('lines')->sole();
    }

    public function test_transport_and_lunch_follow_the_days_worked(): void
    {
        $this->allowances();
        // Days worked is offered as a time input, first; lunch takes its days from there.
        $this->getJson('/api/v1/payroll/time-inputs?year=2026&month=9')->assertOk()
            ->assertJsonPath('data.components.0.code', PayComponent::DAYS_WORKED)
            ->assertJsonMissing(['code' => 'LUNCH_ALLOWANCE']);
        $this->daysWorked(18);

        $slip = $this->calculate();
        $lines = $slip->lines->keyBy('code');

        // The sample payslip: 1,500 × 18/21 = 1,285.71 and 70 × 18 = 1,260.00.
        $this->assertSame(1285.71, (float) $lines['TRANSPORT_ALLOWANCE']->amount);
        $this->assertSame(18.0, (float) $lines['TRANSPORT_ALLOWANCE']->quantity);
        $this->assertSame(1260.0, (float) $lines['LUNCH_ALLOWANCE']->amount);
        $this->assertSame(18.0, (float) $lines['LUNCH_ALLOWANCE']->quantity);
        $this->assertSame(70.0, (float) $lines['LUNCH_ALLOWANCE']->rate);
        // Days worked itself isn't paid.
        $this->assertFalse($lines->has(PayComponent::DAYS_WORKED));
        $this->assertSame([21, 18.0], [(int) $slip->working_days, (float) $slip->days_worked]);
        $this->assertSame(round(3360 + 1285.71 + 1260, 2), (float) $slip->gross_pay);
    }

    public function test_without_days_worked_the_allowances_are_left_out_with_a_warning(): void
    {
        $this->allowances();

        $slip = $this->calculate();

        $this->assertSame(['BASIC'], $slip->lines->where('kind', 'earning')->pluck('code')->values()->all());
        $this->assertContains('Transport allowance: no days worked recorded, so it was left out.', $slip->warnings);
        $this->assertContains('Lunch allowance: no days worked recorded, so it was left out.', $slip->warnings);
        $this->assertNull($slip->days_worked);
    }

    public function test_the_share_is_never_more_than_the_full_amount(): void
    {
        $this->allowances();
        $this->daysWorked(23);

        $lines = $this->calculate()->lines->keyBy('code');

        $this->assertSame(1500.0, (float) $lines['TRANSPORT_ALLOWANCE']->amount);
        $this->assertSame(1610.0, (float) $lines['LUNCH_ALLOWANCE']->amount);
    }

    public function test_days_worked_is_only_offered_when_a_component_follows_it(): void
    {
        $this->getJson('/api/v1/payroll/time-inputs?year=2026&month=9')->assertOk()
            ->assertJsonMissing(['code' => PayComponent::DAYS_WORKED]);
    }

    public function test_overtime_is_paid_at_the_chosen_hourly_basis(): void
    {
        $overtime = PayComponent::create(['code' => 'OT_WD', 'name' => 'Weekday overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 1.25, 'taxable' => true]);
        $run = PayRun::where('uuid', $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->json('data.uuid'))->first();
        PayRunInput::create(['pay_run_id' => $run->id, 'employee_id' => $this->employee->id, 'pay_component_id' => $overtime->id, 'quantity' => 10, 'source' => 'input']);
        $paid = fn () => (float) $this->calculate()->lines->firstWhere('code', 'OT_WD')->amount;

        // 3,360 basic, 10 hours at 1.25:
        $this->assertSame(238.64, $paid());   // ÷ (22 days × 8 h), the default
        app(SettingService::class)->set('payroll.overtime_hourly_basis', 'period_working_days');
        $this->assertSame(250.0, $paid());    // ÷ (21 working days in the period × 8 h)
        app(SettingService::class)->set('payroll.overtime_hourly_basis', 'fixed_hours');
        $this->assertSame(242.31, $paid());   // ÷ 173.33 h
    }
}
