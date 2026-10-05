<?php

namespace Tests\Feature\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Models\Config\Department;
use App\Models\Config\Setting;
use App\Models\EmployeeSupervisor;
use App\Models\Payroll\ApprovalWorkflow;
use App\Models\User;
use App\Notifications\ApprovalRequestNotification;
use App\Services\Payroll\ApprovalWorkflowService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\Support\ApprovalTestSubject;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $requester;
    private User $supervisor;
    private User $unitManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.overtime')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'view-payroll']);

        // Requester in "Inspection" (head: the unit manager), supervised by someone else.
        $this->requester = $this->userWithRole('staff');
        $this->supervisor = $this->userWithRole('staff');
        $this->unitManager = $this->userWithRole('staff');
        $department = Department::create(['name' => 'Inspection', 'hod' => $this->unitManager->employee_id]);
        $this->requester->employee->update(['department_id' => $department->id]);
        EmployeeSupervisor::create(['supervisor_id' => $this->supervisor->employee_id, 'employee_id' => $this->requester->employee_id]);
    }

    /** The Apave-style chain, set up through the API: supervisor (may change hours) → unit manager → HR. */
    private function apaveChain(array $overrides = []): string
    {
        Sanctum::actingAs($this->hr);

        return $this->postJson('/api/v1/payroll/approval-workflows', $overrides + [
            'process' => 'overtime',
            'name'    => 'Overtime',
            'steps'   => [
                ['name' => 'Supervisor', 'approver_type' => 'supervisor', 'can_adjust' => ['approved_hours']],
                ['name' => 'Unit manager', 'approver_type' => 'department_head'],
                ['name' => 'HR', 'approver_type' => 'permission', 'approver_value' => 'configure-payroll'],
            ],
        ])->assertCreated()->json('data.uuid');
    }

    private function request(float $hours = 6): ApprovalTestSubject
    {
        return ApprovalTestSubject::create(['approved_hours' => $hours]);
    }

    private function start(ApprovalTestSubject $subject)
    {
        return app(ApprovalWorkflowService::class)->start($subject, ApprovalProcess::OVERTIME, $this->requester->employee, $this->requester);
    }

    public function test_a_workflow_is_configured_previewed_and_its_first_one_becomes_the_default(): void
    {
        $first = $this->apaveChain();
        $this->getJson('/api/v1/payroll/approval-workflows')->assertOk()
            ->assertJsonPath('data.workflows.0.is_default', true)
            ->assertJsonPath('data.workflows.0.steps.2.approver_value', 'configure-payroll')
            ->assertJsonPath('data.options.processes.0.adjustable.0.value', 'approved_hours');

        $this->getJson("/api/v1/payroll/approval-workflows/{$first}/preview?employee_uuid={$this->requester->employee->uuid}")->assertOk()
            ->assertJsonPath('data.0.approvers', [$this->supervisor->name])
            ->assertJsonPath('data.1.approvers', [$this->unitManager->name])
            ->assertJsonPath('data.2.fallback', false);

        // A second one made default takes over; steps can't name an unknown role; people are picked by uuid.
        $this->postJson('/api/v1/payroll/approval-workflows', ['process' => 'overtime', 'name' => 'Bad', 'steps' => [['name' => 'X', 'approver_type' => 'role', 'approver_value' => 'nobody']]])
            ->assertUnprocessable()->assertJsonValidationErrors('steps.0.approver_value');
        $second = $this->postJson('/api/v1/payroll/approval-workflows', [
            'process' => 'overtime', 'name' => 'Short', 'is_default' => true,
            'steps' => [['name' => 'Payroll officer', 'approver_type' => 'users', 'approver_value' => [$this->hr->uuid]]],
        ])->assertCreated()->assertJsonPath('data.steps.0.approver_value.0.name', $this->hr->name)->json('data.uuid');
        $this->assertFalse(ApprovalWorkflow::where('uuid', $first)->value('is_default'));
        $this->assertTrue(ApprovalWorkflow::where('uuid', $second)->value('is_default'));

        Sanctum::actingAs($this->supervisor);
        $this->getJson('/api/v1/payroll/approval-workflows')->assertForbidden();
    }

    public function test_a_request_follows_the_chain_with_adjustments_only_where_allowed(): void
    {
        $this->apaveChain();
        $subject = $this->request(6);
        $approval = $this->start($subject);
        $service = app(ApprovalWorkflowService::class);

        $this->assertSame([$this->supervisor->id], $approval->current_approver_ids);
        Notification::assertSentTo($this->supervisor, ApprovalRequestNotification::class);

        // Only the current step's approvers decide; the requester never does.
        foreach ([$this->requester, $this->unitManager] as $outsider) {
            try {
                $service->decide($approval, $outsider, 'approved');
                $this->fail('Should not be allowed');
            } catch (\App\Exceptions\UserFacingException $e) {
                $this->assertSame(403, $e->status());
            }
        }

        // The supervisor cuts the hours; the change is recorded.
        $approval = $service->decide($approval, $this->supervisor, 'approved', null, ['approved_hours' => 4]);
        $this->assertEquals(4, $subject->fresh()->approved_hours);
        $this->assertSame(['approved_hours' => ['from' => '6.0', 'to' => 4]], $approval->decisions->first()->adjustments);
        $this->assertSame([$this->unitManager->id], $approval->current_approver_ids);

        // The unit manager may not change hours, and must say why when rejecting.
        $this->expectFailure(fn () => $service->decide($approval, $this->unitManager, 'approved', null, ['approved_hours' => 8]), 'can\'t change');
        $this->expectFailure(fn () => $service->decide($approval, $this->unitManager, 'rejected'), 'Say why');
        $approval = $service->decide($approval, $this->unitManager, 'approved');
        $this->assertSame([$this->hr->id], $approval->current_approver_ids);

        $approval = $service->decide($approval, $this->hr, 'approved', 'OK for payroll');
        $this->assertSame('approved', $approval->status);
        $this->assertSame('approved', $subject->fresh()->outcome);
        $this->assertCount(3, $approval->decisions);
    }

    public function test_editing_a_workflow_does_not_change_requests_under_way(): void
    {
        $workflow = $this->apaveChain();
        $approval = $this->start($this->request());

        $this->putJson("/api/v1/payroll/approval-workflows/{$workflow}", ['name' => 'Overtime', 'steps' => [['name' => 'HR only', 'approver_type' => 'permission', 'approver_value' => 'configure-payroll']]])->assertOk();

        $this->assertSame(['Supervisor', 'Unit manager', 'HR'], collect($approval->fresh()->steps)->pluck('name')->all());
        $this->assertSame(['HR only'], collect($this->start($this->request())->steps)->pluck('name')->all());
    }

    public function test_nobody_to_ask_goes_to_hr_and_no_workflow_means_hr_approves(): void
    {
        // Without any workflow: a single HR step.
        $approval = $this->start($this->request());
        $this->assertSame(['HR approval'], collect($approval->steps)->pluck('name')->all());
        $this->assertSame([$this->hr->id], $approval->current_approver_ids);

        // A supervisor step for someone without a supervisor: HR is asked instead.
        $this->apaveChain();
        EmployeeSupervisor::query()->delete();
        $this->assertSame([$this->hr->id], $this->start($this->request())->current_approver_ids);
    }

    public function test_distinct_approvers_keep_one_person_from_approving_twice(): void
    {
        $second = $this->userWithRole('hr');
        $second->givePermissionTo('configure-payroll');
        $this->apaveChain(['distinct_approvers' => true, 'steps' => [
            ['name' => 'Payroll', 'approver_type' => 'permission', 'approver_value' => 'configure-payroll'],
            ['name' => 'Finance', 'approver_type' => 'permission', 'approver_value' => 'configure-payroll'],
        ]]);
        $service = app(ApprovalWorkflowService::class);

        $approval = $service->decide($this->start($this->request()), $this->hr, 'approved');
        $this->assertSame([$second->id], $approval->current_approver_ids);
    }

    private function expectFailure(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected: {$message}");
        } catch (\App\Exceptions\UserFacingException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
