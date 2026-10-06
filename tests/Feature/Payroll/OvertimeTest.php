<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Holiday;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class OvertimeTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $staff;
    private string $weekday;
    private string $weekend;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.overtime'])->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'view-payroll', 'view-overtime']);
        $this->staff = $this->userWithRole('staff');
        $this->staff->employee->update(['staff_id' => 'AI001', 'ssnit_number' => 'C1', 'job_type' => 'full_time']);
        EmployeePayProfile::create(['employee_id' => $this->staff->employee->id, 'effective_from' => '2026-01-01', 'basic_salary' => 4400, 'payment_method' => 'cash']);

        Sanctum::actingAs($this->hr);
        $wd = PayComponent::create(['code' => 'OT_WD', 'name' => 'Weekday overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 1.5, 'taxable' => true]);
        $we = PayComponent::create(['code' => 'OT_WE', 'name' => 'Weekend overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 2, 'taxable' => true]);
        $this->weekday = $this->postJson('/api/v1/payroll/overtime-types', ['name' => 'Weekday', 'pay_component_uuid' => $wd->uuid, 'applies_on' => 'weekday'])->assertCreated()->json('data.uuid');
        $this->weekend = $this->postJson('/api/v1/payroll/overtime-types', ['name' => 'Weekend & holiday', 'pay_component_uuid' => $we->uuid, 'applies_on' => 'weekend'])->assertCreated()->json('data.uuid');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request(array $data)
    {
        return $this->postJson('/api/v1/payroll/my-overtime', $data + ['reason' => 'Shutdown work']);
    }

    public function test_the_type_comes_from_the_day_and_the_policy_limits_are_checked(): void
    {
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/payroll/my-overtime/type-for?date=2026-06-27')->assertJsonPath('data.day', 'weekend')->assertJsonPath('data.type.uuid', $this->weekend);
        Holiday::create(['description' => 'Republic Day', 'start_date' => '2026-07-01', 'end_date' => '2026-07-01']);
        $this->getJson('/api/v1/payroll/my-overtime/type-for?date=2026-07-01')->assertJsonPath('data.day', 'holiday');

        $this->request(['work_date' => '2026-06-26', 'hours' => 4])->assertCreated()->assertJsonPath('data.type.name', 'Weekday');
        $this->request(['work_date' => '2026-06-26', 'hours' => 9])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '12 hours of overtime a day'));
        $this->request(['work_date' => '2026-07-02', 'hours' => 2])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'after it is worked'));
        $this->request(['work_date' => '2026-04-01', 'hours' => 2])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'within 31 days'));
        $this->request(['work_date' => '2026-06-25', 'hours' => 2, 'reason' => ''])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'what the overtime was for'));

        // Eligibility by job type, and a per-employee override on the pay profile.
        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/payroll/settings', ['overtime_eligibility' => 'job_types', 'overtime_job_types' => ['contract']])->assertOk();
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/payroll/my-overtime/options')->assertJsonPath('data.eligible', false);
        EmployeePayProfile::query()->update(['overtime_eligible' => true]);
        $this->getJson('/api/v1/payroll/my-overtime/options')->assertJsonPath('data.eligible', true);
    }

    public function test_an_approved_request_with_adjusted_hours_is_paid_once_by_the_next_run(): void
    {
        Sanctum::actingAs($this->staff);
        $this->request(['work_date' => '2026-06-26', 'hours' => 10])->assertCreated();
        $this->request(['work_date' => '2026-06-27', 'hours' => 4])->assertCreated();
        $withdrawn = $this->request(['work_date' => '2026-06-25', 'hours' => 1])->json('data.uuid');
        $this->postJson("/api/v1/payroll/my-overtime/{$withdrawn}/cancel")->assertOk();
        $this->getJson('/api/v1/payroll/approvals')->assertJsonPath('data.meta.total', 0); // not their own
        $this->getJson('/api/v1/payroll/my-overtime?from=2026-06-01&to=2026-06-30&status=pending')->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.summary.hours_requested', 14);
        $this->getJson("/api/v1/payroll/my-overtime?type={$this->weekend}")->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/v1/payroll/my-overtime?from=2026-05-01&to=2026-05-31')->assertJsonPath('data.meta.total', 0);

        // HR (the fallback approver with no workflow set up) cuts the weekday hours to 8.
        Sanctum::actingAs($this->hr);
        $inbox = $this->getJson('/api/v1/payroll/approvals')->assertOk()->assertJsonPath('data.meta.waiting_count', 2)->json('data.data');
        $this->assertSame('approved_hours', $inbox[0]['adjustable'][0]['field']);
        $this->postJson("/api/v1/payroll/approvals/{$inbox[0]['uuid']}/decide", ['decision' => 'approved', 'adjustments' => ['approved_hours' => 12]])->assertStatus(422);
        $this->postJson("/api/v1/payroll/approvals/{$inbox[0]['uuid']}/decide", ['decision' => 'approved', 'adjustments' => ['approved_hours' => 8]])->assertOk();
        $this->postJson("/api/v1/payroll/approvals/{$inbox[1]['uuid']}/decide", ['decision' => 'approved'])->assertOk();
        $this->getJson('/api/v1/payroll/approvals?tab=decided')->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/payroll/overtime?status=approved')->assertJsonPath('data.summary.approved_hours', 12);
        $this->get('/api/v1/payroll/overtime/export')->assertOk();

        // 4,400 ÷ 22 ÷ 8 = 25 an hour: 8 h × 1.5 + 4 h × 2 = 300 + 200.
        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk()->assertJsonPath('data.totals.gross_pay', 4900);
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk()->assertJsonPath('data.totals.gross_pay', 4900);
        $inputs = $this->getJson("/api/v1/payroll/runs/{$run}/inputs")->assertJsonCount(2, 'data')->json('data');
        $this->assertSame('overtime', $inputs[0]['source']);
        $this->deleteJson("/api/v1/payroll/runs/{$run}/inputs/{$inputs[0]['uuid']}")->assertStatus(422);
        $this->assertSame(2, OvertimeRequest::whereNotNull('pay_run_id')->count());

        // HR records overtime for an employee; it goes through approval like any other.
        $this->postJson('/api/v1/payroll/overtime', ['employee_uuid' => $this->staff->employee->uuid, 'work_date' => '2026-06-29', 'hours' => 2, 'reason' => 'Audit'])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        Sanctum::actingAs($this->staff);
        $this->postJson('/api/v1/payroll/overtime', ['employee_uuid' => $this->staff->employee->uuid, 'work_date' => '2026-06-29', 'hours' => 2])->assertForbidden();
        Sanctum::actingAs($this->hr);

        // Removing the run hands the overtime back for the next one.
        $this->deleteJson("/api/v1/payroll/runs/{$run}")->assertOk();
        $this->assertSame(0, OvertimeRequest::whereNotNull('pay_run_id')->count());
    }
}
