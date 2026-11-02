<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\Payroll\WorkOrder;
use App\Models\Payroll\WorkSite;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Overtime approved before the work (FM-07): its fields, the supervisor's questions, and paying it. */
class OvertimeInAdvanceTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $staff;
    private WorkOrder $po;
    private WorkSite $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 09:00:00');
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.overtime'])->update(['value' => true]);
        $settings = app(SettingService::class);
        $settings->refreshCache();
        $settings->set('payroll.overtime_mode', 'before');
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'view-payroll', 'view-overtime']);
        $this->staff = $this->userWithRole('staff');
        $this->staff->employee->update(['ssnit_number' => 'C1']);
        EmployeePayProfile::create(['employee_id' => $this->staff->employee_id, 'effective_from' => '2026-01-01', 'basic_salary' => 4400, 'payment_method' => 'cash']);
        $this->po = WorkOrder::create(['number' => 'PO# 4500028551', 'description' => 'NDT inspection']);
        $this->site = WorkSite::create(['code' => 'MV25', 'name' => 'FPSO MV25', 'is_offshore' => true]);

        Sanctum::actingAs($this->hr);
        $component = PayComponent::create(['code' => 'OT_WD', 'name' => 'Weekday overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 1.25, 'taxable' => true]);
        $this->postJson('/api/v1/payroll/overtime-types', ['name' => 'Weekday', 'pay_component_uuid' => $component->uuid, 'applies_on' => 'any'])->assertCreated();
        // The supervisor's step asks FM-07's two questions.
        $this->postJson('/api/v1/payroll/approval-workflows', [
            'process' => 'overtime', 'name' => 'FM-07', 'is_default' => true,
            'steps' => [[
                'name' => 'Supervisor', 'approver_type' => 'permission', 'approver_value' => 'configure-payroll',
                'can_adjust' => ['approved_hours'], 'questions' => ['can_postpone', 'on_client_timesheet', 'not_a_question'],
            ]],
        ])->assertCreated()->assertJsonPath('data.steps.0.questions', ['can_postpone', 'on_client_timesheet']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ask(array $data)
    {
        Sanctum::actingAs($this->staff);

        return $this->postJson('/api/v1/payroll/my-overtime', $data + ['hours' => 4, 'reason' => 'Vessel leaves on Friday']);
    }

    private function approve(OvertimeRequest $request, array $answers = ['can_postpone' => false, 'on_client_timesheet' => true])
    {
        Sanctum::actingAs($this->hr);

        return $this->postJson("/api/v1/payroll/approvals/{$request->approval->uuid}/decide", ['decision' => 'approved', 'answers' => $answers]);
    }

    private function paidHours(): ?float
    {
        Sanctum::actingAs($this->hr);
        $run = PayRun::where(['year' => 2026, 'month' => 9])->first()
            ?? PayRun::where('uuid', $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->json('data.uuid'))->first();
        $this->postJson("/api/v1/payroll/runs/{$run->uuid}/calculate")->assertOk();
        $line = $run->payslips()->sole()->lines->firstWhere('code', 'OT_WD');

        return $line ? (float) $line->quantity : null;
    }

    public function test_overtime_is_requested_before_the_work_with_the_forms_details(): void
    {
        $this->getJson('/api/v1/payroll/my-overtime/options')->assertJsonPath('data.mode', 'before');
        $this->ask(['work_date' => '2026-08-31', 'work_order_uuid' => $this->po->uuid])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'before it is worked'));
        $this->ask(['work_date' => '2026-10-15', 'work_order_uuid' => $this->po->uuid])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'up to 30 days ahead'));
        $this->ask(['work_date' => '2026-09-03'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'PO, work order or project'));
        $this->ask(['work_date' => '2026-09-03', 'activity_description' => 'Blasting', 'reason' => ''])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'reason it is urgent'));

        $this->ask(['work_date' => '2026-09-03', 'work_order_uuid' => $this->po->uuid, 'work_site_uuid' => $this->site->uuid])->assertCreated()
            ->assertJsonPath('data.work_order.number', 'PO# 4500028551')
            ->assertJsonPath('data.location', 'FPSO MV25')
            ->assertJsonPath('data.requested_in_advance', true);
        $this->ask(['work_date' => '2026-09-01', 'activity_description' => 'Rope access on the flare tower'])->assertCreated()
            ->assertJsonPath('data.work_order', null);
    }

    public function test_the_supervisor_answers_the_forms_questions_to_approve(): void
    {
        $this->ask(['work_date' => '2026-09-03', 'work_order_uuid' => $this->po->uuid])->assertCreated();
        $request = OvertimeRequest::sole();

        $this->approve($request, [])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Can the work be postponed'));
        $this->approve($request, ['can_postpone' => false, 'on_client_timesheet' => true, 'other' => true])->assertStatus(422);
        $this->approve($request, ['can_postpone' => 'no', 'on_client_timesheet' => 'yes'])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.steps.0.questions.0.answer', false)
            ->assertJsonPath('data.steps.0.questions.1.answer', true);

        $request->refresh();
        $this->assertSame([false, true], [$request->can_postpone, $request->on_client_timesheet]);
    }

    public function test_a_rejection_needs_no_answers(): void
    {
        $this->ask(['work_date' => '2026-09-03', 'work_order_uuid' => $this->po->uuid])->assertCreated();
        Sanctum::actingAs($this->hr);

        $this->postJson('/api/v1/payroll/approvals/' . OvertimeRequest::sole()->approval->uuid . '/decide', ['decision' => 'rejected', 'comment' => 'Can wait'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_approved_in_advance_it_is_paid_as_approved(): void
    {
        $this->ask(['work_date' => '2026-09-03', 'work_order_uuid' => $this->po->uuid])->assertCreated();
        $this->approve(OvertimeRequest::sole())->assertOk();

        $this->assertSame(4.0, $this->paidHours());
    }

    public function test_with_confirmation_it_waits_for_the_hours_worked_and_pays_by_the_basis(): void
    {
        app(SettingService::class)->set('payroll.overtime_mode', 'both');
        $this->ask(['work_date' => '2026-09-03', 'work_order_uuid' => $this->po->uuid])->assertCreated();
        $request = OvertimeRequest::sole();
        $this->approve($request)->assertOk();

        // Not before the work is done.
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-overtime/{$request->uuid}/actual-hours", ['actual_hours' => 3])->assertStatus(422);

        Carbon::setTestNow('2026-09-04 09:00:00');
        $this->getJson('/api/v1/payroll/my-overtime')->assertJsonPath('data.data.0.awaiting_actual', true);
        // Waiting: not paid, and the payslip says why.
        $this->assertNull($this->paidHours());
        $this->assertStringContainsString('waiting for the hours worked', implode(' ', PayRun::sole()->payslips()->sole()->warnings));

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-overtime/{$request->uuid}/actual-hours", ['actual_hours' => 3])->assertOk()
            ->assertJsonPath('data.actual_hours', 3)->assertJsonPath('data.awaiting_actual', false);

        $this->assertSame(3.0, $this->paidHours());   // the lower of 4 approved and 3 worked
        app(SettingService::class)->set('payroll.overtime_pay_basis', 'approved');
        $this->assertSame(4.0, $this->paidHours());
        app(SettingService::class)->set('payroll.overtime_pay_basis', 'actual');
        $this->assertSame(3.0, $this->paidHours());
    }

    public function test_claimed_after_the_work_is_unchanged(): void
    {
        app(SettingService::class)->set('payroll.overtime_mode', 'after');
        $this->ask(['work_date' => '2026-09-03'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'after it is worked'));
        $this->ask(['work_date' => '2026-08-31'])->assertCreated()->assertJsonPath('data.requested_in_advance', false);
        $this->approve(OvertimeRequest::sole())->assertOk();

        $this->assertSame(4.0, $this->paidHours());
    }
}
