<?php

namespace Tests\Feature;

use App\Models\Config\Department;
use App\Models\Config\LeaveType;
use App\Models\LeaveRequest;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Services\Leave\LeaveApprover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class LeaveApprovalChainTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $opsHead;   // heads Operations
    private User $ndtHead;   // heads NDT (under Operations)
    private Department $ops;
    private Department $ndt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess(['leave.enabled', 'leave.self_service', 'leave.team_visibility']);
        Notification::fake();
        Mail::fake();

        $this->opsHead = $this->userWithRole('hod');
        $this->ndtHead = $this->userWithRole('hod');
        $this->ops = Department::create(['name' => 'Operations', 'hod' => $this->opsHead->employee_id]);
        $this->ndt = Department::create(['name' => 'NDT', 'hod' => $this->ndtHead->employee_id, 'parent_department_id' => $this->ops->id]);
        $this->ndtHead->employee->update(['department_id' => $this->ndt->id]);
        $this->opsHead->employee->update(['department_id' => $this->ops->id]);
    }

    private function staffIn(Department $department): Employee
    {
        $employee = $this->userWithRole('staff')->employee;
        $employee->update(['department_id' => $department->id]);

        return $employee->fresh();
    }

    private function approver(Employee $employee): ?int
    {
        return app(LeaveApprover::class)->for($employee->fresh())?->id;
    }

    private function leaveTypeId(): int
    {
        return LeaveType::where('name', 'Annual')->value('id') ?? DB::table('leave_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Annual', 'entitlement_type' => 'fixed', 'number_of_days' => 20,
            'start_of_annual_cycle' => now()->startOfYear()->toDateString(), 'request_type' => 'days',
            'user_id' => $this->opsHead->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function request(Employee $employee, int $supervisorId): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id'    => $employee->id,
            'supervisor_id'  => $supervisorId,
            'department_id'  => $employee->department_id,
            'leave_type_id'  => $this->leaveTypeId(),
            'days_requested' => 2,
            'start_date'     => now()->addDays(10)->toDateString(),
            'end_date'       => now()->addDays(11)->toDateString(),
            'status'         => 'pending',
            'reason'         => 'Family event',
            'user_id'        => User::where('employee_id', $employee->id)->value('id'),
        ]);
    }

    public function test_the_approver_is_the_nearest_head_above_the_employee(): void
    {
        $staff = $this->staffIn($this->ndt);
        $this->assertSame($this->ndtHead->employee_id, $this->approver($staff));

        // A department head's leave goes to the head of the department above.
        $this->assertSame($this->opsHead->employee_id, $this->approver($this->ndtHead->employee));

        // A head sitting in a sub-department is never approved by someone they lead.
        $this->opsHead->employee->update(['department_id' => $this->ndt->id]);
        $this->assertNull($this->approver($this->opsHead->employee));
        $this->assertTrue(app(LeaveApprover::class)->headsOwnChain($this->opsHead->employee->fresh()));
    }

    public function test_a_department_without_a_head_falls_back_to_the_parents_head(): void
    {
        $records = Department::create(['name' => 'Records', 'parent_department_id' => $this->ndt->id]);
        $staff = $this->staffIn($records);
        $this->assertSame($this->ndtHead->employee_id, $this->approver($staff));

        $this->ndt->update(['hod' => null]);
        $this->assertSame($this->opsHead->employee_id, $this->approver($staff));

        // Nobody above at all.
        $lonely = $this->staffIn(Department::create(['name' => 'Annex']));
        $this->assertNull($this->approver($lonely));
        $this->assertFalse(app(LeaveApprover::class)->headsOwnChain($lonely));
    }

    public function test_an_escalated_request_is_approved_by_the_parent_head_not_the_requester(): void
    {
        $request = $this->request($this->ndtHead->employee, $this->opsHead->employee_id);

        // The NDT head heads the request's department, but can't approve their own leave.
        Sanctum::actingAs($this->ndtHead);
        $this->postJson('/api/v1/change-leave-status', [
            'id' => $request->uuid, 'decision' => 'approved', 'status' => 'hod_approved',
            'days_requested' => 2, 'start_date' => $request->start_date,
        ])->assertForbidden();
        $this->assertEmpty($this->getJson('/api/v1/team-request')->json('data'));

        Sanctum::actingAs($this->opsHead);
        $this->assertSame($request->uuid, $this->getJson('/api/v1/team-request')->json('data.0.uuid'));
        $this->postJson('/api/v1/change-leave-status', [
            'id' => $request->uuid, 'decision' => 'approved', 'status' => 'hod_approved',
            'days_requested' => 2, 'start_date' => $request->start_date,
        ])->assertOk();
        $this->assertSame('hod_approved', $request->fresh()->status->value);
    }

    public function test_a_head_cancels_requests_from_the_department_they_head_wherever_they_sit(): void
    {
        // The NDT head is recorded as a member of Operations.
        $this->ndtHead->employee->update(['department_id' => $this->ops->id]);
        $request = $this->request($this->staffIn($this->ndt), $this->ndtHead->employee_id);

        Sanctum::actingAs($this->ndtHead);
        $this->postJson("/api/v1/leave-requests/{$request->uuid}/cancel")->assertOk();
        $this->assertSame('canceled', $request->fresh()->status->value);
    }
}
