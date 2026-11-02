<?php

namespace Tests\Feature\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Models\Config\Department;
use App\Models\Config\Setting;
use App\Models\Payroll\ApprovalWorkflow;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Services\Payroll\ApprovalWorkflowService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\Support\ApprovalTestSubject;
use Tests\TestCase;

/**
 * "Heads up the chain" steps: one workflow gives each employee as many steps as there are heads above them.
 * Technical Directorate › Engineering › Inspection unit, and Stores (no head) under Engineering.
 */
class ApprovalChainTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $director;
    private User $engHead;
    private User $unitHead;
    private Department $inspection;
    private Department $stores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.overtime')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'view-payroll']);
        $this->director = $this->userWithRole('staff');
        $this->engHead = $this->userWithRole('staff');
        $this->unitHead = $this->userWithRole('staff');
        $directorate = Department::create(['name' => 'Technical Directorate', 'hod' => $this->director->employee_id]);
        $engineering = Department::create(['name' => 'Engineering', 'hod' => $this->engHead->employee_id, 'parent_department_id' => $directorate->id]);
        $this->inspection = Department::create(['name' => 'Inspection', 'hod' => $this->unitHead->employee_id, 'parent_department_id' => $engineering->id]);
        $this->stores = Department::create(['name' => 'Stores', 'parent_department_id' => $engineering->id]);
        $this->director->employee->update(['department_id' => $directorate->id]);
        $this->engHead->employee->update(['department_id' => $engineering->id]);
        $this->unitHead->employee->update(['department_id' => $this->inspection->id]);
    }

    private function staffIn(Department $department): Employee
    {
        $employee = $this->userWithRole('staff')->employee;
        $employee->update(['department_id' => $department->id]);

        return $employee->fresh();
    }

    /** Supervisor → heads up the chain → HR, through the API. */
    private function workflow(array $chain = ['stop' => 'top'], ?array $steps = null): ApprovalWorkflow
    {
        Sanctum::actingAs($this->hr);
        $uuid = $this->postJson('/api/v1/payroll/approval-workflows', [
            'process' => 'overtime', 'name' => 'Overtime',
            'steps'   => $steps ?? [
                ['name' => 'Supervisor', 'approver_type' => 'supervisor'],
                ['name' => 'Heads', 'approver_type' => 'heads_chain', 'approver_value' => $chain],
                ['name' => 'HR', 'approver_type' => 'permission', 'approver_value' => 'configure-payroll'],
            ],
        ])->assertCreated()->json('data.uuid');

        return ApprovalWorkflow::where('uuid', $uuid)->with('steps')->first();
    }

    private function names(ApprovalWorkflow $workflow, Employee $employee): array
    {
        return array_column(app(ApprovalWorkflowService::class)->stepsFor($workflow->steps, ApprovalProcess::OVERTIME, $employee), 'name');
    }

    public function test_one_workflow_gives_each_employee_the_heads_above_them(): void
    {
        $workflow = $this->workflow();

        // A unit officer: their unit head as supervisor, then every head above.
        $this->assertSame(['Supervisor', 'Head of Engineering', 'Head of Technical Directorate', 'HR'], $this->names($workflow, $this->staffIn($this->inspection)));
        // Stores has no head: the engineering head supervises, so only the director is left.
        $this->assertSame(['Supervisor', 'Head of Technical Directorate', 'HR'], $this->names($workflow, $this->staffIn($this->stores)));
        // A department head: the director supervises; nobody else above.
        $this->assertSame(['Supervisor', 'HR'], $this->names($workflow, $this->engHead->employee->fresh()));

        // Positions run 1, 2, 3… and the expanded steps name one person each.
        $steps = app(ApprovalWorkflowService::class)->stepsFor($workflow->steps, ApprovalProcess::OVERTIME, $this->staffIn($this->inspection));
        $this->assertSame([1, 2, 3, 4], array_column($steps, 'position'));
        $this->assertSame(['users', [$this->engHead->id]], [$steps[1]['approver_type'], $steps[1]['approver_value']]);

        // The preview shows the same.
        $officer = $this->staffIn($this->inspection);
        $this->getJson("/api/v1/payroll/approval-workflows/{$workflow->uuid}/preview?employee_uuid={$officer->uuid}")->assertOk()
            ->assertJsonPath('data.1.name', 'Head of Engineering')->assertJsonPath('data.1.approvers.0', $this->engHead->name);
    }

    public function test_the_chain_stops_after_a_number_and_folds_repeats(): void
    {
        $officer = $this->staffIn($this->inspection);
        $this->assertSame(['Supervisor', 'Head of Engineering', 'HR'], $this->names($this->workflow(['stop' => 'count', 'count' => 1]), $officer));

        // Keeping the supervisor in the chain: the unit head would decide twice in a row, so it's one step.
        $this->assertSame(['Supervisor', 'Head of Engineering', 'Head of Technical Directorate', 'HR'],
            $this->names($this->workflow(['stop' => 'top', 'skip_supervisor' => false]), $officer));

        // With "Reports to" someone outside the chain, the unit head is no longer the supervisor and stays in it.
        $lead = $this->staffIn($this->inspection);
        $officer->update(['reports_to_id' => $lead->id]);
        $this->assertSame(['Supervisor', 'Head of Inspection', 'Head of Engineering', 'Head of Technical Directorate', 'HR'],
            $this->names($this->workflow(), $officer->fresh()));
    }

    public function test_a_request_goes_head_by_head_to_hr(): void
    {
        $workflow = $this->workflow();
        $officer = $this->userWithRole('staff');
        $officer->employee->update(['department_id' => $this->inspection->id]);
        $service = app(ApprovalWorkflowService::class);
        $approval = $service->start(ApprovalTestSubject::create(['approved_hours' => 4]), ApprovalProcess::OVERTIME, $officer->employee->fresh(), $officer, $workflow);

        foreach ([$this->unitHead, $this->engHead, $this->director, $this->hr] as $approver) {
            $this->assertSame([$approver->id], $approval->fresh()->current_approver_ids);
            $approval = $service->decide($approval->fresh(), $approver, 'approved');
        }
        $this->assertSame('approved', $approval->status);
        $this->assertSame(['Supervisor', 'Head of Engineering', 'Head of Technical Directorate', 'HR'], $approval->decisions()->orderBy('position')->pluck('step_name')->all());
    }

    public function test_the_rule_is_checked(): void
    {
        Sanctum::actingAs($this->hr);
        $post = fn (array $value) => $this->postJson('/api/v1/payroll/approval-workflows', [
            'process' => 'overtime', 'name' => 'Overtime', 'steps' => [['name' => 'Heads', 'approver_type' => 'heads_chain', 'approver_value' => $value]],
        ]);
        $post(['stop' => 'level'])->assertJsonValidationErrors('steps.0.approver_value');
        $post(['stop' => 'count', 'count' => 11])->assertJsonValidationErrors('steps.0.approver_value');
        $post(['stop' => 'count', 'count' => 2])->assertCreated()
            ->assertJsonPath('data.steps.0.approver_value', ['stop' => 'count', 'skip_supervisor' => true, 'count' => 2]);
    }
}
