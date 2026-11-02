<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Counts and lists that read the leave workflow: pending → hod_* (HOD) → hr_* (HR). */
class LeaveStatusQueriesTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess(['leave.enabled', 'leave.hr_approval']);
        $this->hr = $this->userWithRole('hr');
        $this->typeId = DB::table('leave_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Annual', 'entitlement_type' => 'fixed', 'number_of_days' => 20,
            'start_of_annual_cycle' => now()->startOfYear()->toDateString(), 'request_type' => 'days',
            'user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function leave(string $status, string $start, string $end): LeaveRequest
    {
        $employee = $this->userWithRole('staff')->employee;

        return LeaveRequest::create([
            'employee_id' => $employee->id, 'supervisor_id' => $employee->id, 'department_id' => $employee->department_id,
            'leave_type_id' => $this->typeId, 'days_requested' => 2, 'reason' => 'Rest', 'user_id' => $this->hr->id,
            'status' => $status, 'hr_status' => str_starts_with($status, 'hr_') ? substr($status, 3) : 'pending',
            'start_date' => $start, 'end_date' => $end,
        ]);
    }

    public function test_approved_leave_is_counted_and_listed(): void
    {
        $today = now()->toDateString();
        $onLeave = $this->leave('hr_approved', now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $awaitingHr = $this->leave('hod_approved', $today, $today);
        $this->leave('pending', $today, $today);
        $this->leave('hr_approved', now()->subMonths(2)->toDateString(), now()->subMonths(2)->addDay()->toDateString());

        Sanctum::actingAs($this->hr);

        $this->getJson('/api/v1/stats/employee-management')->assertOk()->assertJsonPath('on_leave', 1);

        $out = collect($this->getJson('/api/v1/who-is-out')->assertOk()->json())->pluck('uuid');
        $this->assertSame([$onLeave->uuid], $out->all());

        $this->getJson('/api/v1/notifications/navs')->assertOk()
            ->assertJsonPath('leave_request.approved', 2)
            ->assertJsonPath('leave_request.pending', 1);

        $listed = collect($this->getJson('/api/v1/leave-management/leave-requests')->assertOk()->json('data'))->pluck('uuid');
        $this->assertEqualsCanonicalizing([$onLeave->uuid, $awaitingHr->uuid, LeaveRequest::where('status', 'hr_approved')->where('id', '!=', $onLeave->id)->value('uuid')], $listed->all());
    }
}
