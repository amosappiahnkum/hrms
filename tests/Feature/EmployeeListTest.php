<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class EmployeeListTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_the_list_gives_quick_details_for_each_employee(): void
    {
        $this->setUpAccess([]);
        $hr = $this->userWithRole('hr');
        $employee = $this->userWithRole('staff')->employee;
        $employee->jobDetail()->update([
            'position_id'       => Position::create(['name' => 'HR Officer'])->id,
            'joined_date'       => now()->subYears(3)->subMonth()->toDateString(),
            'contract_end_date' => now()->addDays(20)->toDateString(),
        ]);
        $employee->contactDetail()->update(['telephone' => '0240000000']);

        Sanctum::actingAs($hr);
        $row = collect($this->getJson('/api/v1/employees?per_page=50')->assertOk()->json('data'))
            ->firstWhere('uuid', $employee->uuid);

        $this->assertSame('HR Officer', $row['position']);
        $this->assertSame(3, $row['years_of_service']);
        $this->assertSame(now()->addDays(20)->toDateString(), $row['contract_end_date']);
        $this->assertSame('0240000000', $row['telephone']);
        $this->assertNull($row['on_leave']);
        $this->assertFalse($row['pending_update']);
        $this->assertTrue($row['has_account']);
    }

    public function test_filters_take_several_values_and_job_type(): void
    {
        $this->setUpAccess([]);
        $hr = $this->userWithRole('hr');
        $audit = \App\Models\Config\Department::create(['name' => 'Audit', 'level' => 0]);
        $tax = \App\Models\Config\Department::create(['name' => 'Tax', 'level' => 0]);
        $taxDesk = \App\Models\Config\Department::create(['name' => 'Tax desk', 'level' => 1, 'parent_department_id' => $tax->id]);
        $legal = \App\Models\Config\Department::create(['name' => 'Legal', 'level' => 0]);
        $a = $this->userWithRole('staff')->employee;
        $a->update(['department_id' => $audit->id, 'job_type' => 'full_time', 'gender' => 'Female']);
        $b = $this->userWithRole('staff')->employee;
        $b->update(['department_id' => $taxDesk->id, 'job_type' => 'contract', 'gender' => 'Male']);
        $c = $this->userWithRole('staff')->employee;
        $c->update(['department_id' => $legal->id, 'job_type' => 'contract', 'gender' => 'Female']);

        Sanctum::actingAs($hr);
        $uuids = fn (string $query) => collect($this->getJson("/api/v1/employees?per_page=50&{$query}")->assertOk()->json('data'))->pluck('uuid')->sort()->values()->all();
        $sorted = fn (...$employees) => collect($employees)->pluck('uuid')->sort()->values()->all();

        // Several departments (as the list sends them, comma-separated, or as an array), sub-departments included.
        $this->assertSame($sorted($a, $b), $uuids("department={$audit->id},{$tax->id}"));
        $this->assertSame($sorted($a, $b), $uuids("department[]={$audit->id}&department[]={$tax->id}"));
        $this->assertSame($sorted($b, $c), $uuids('job_type=contract'));
        $this->assertSame($sorted($c), $uuids('job_type=contract&gender=Female'));
        $this->assertCount(3, array_intersect($sorted($a, $b, $c), $uuids('gender=all&department=')));
    }

    public function test_employees_on_approved_leave_today_are_flagged(): void
    {
        $this->setUpAccess([]);
        $hr = $this->userWithRole('hr');
        $employee = $this->userWithRole('staff')->employee;
        $typeId = DB::table('leave_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Annual', 'entitlement_type' => 'fixed', 'number_of_days' => 20,
            'start_of_annual_cycle' => now()->startOfYear()->toDateString(), 'request_type' => 'days',
            'user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id, 'supervisor_id' => $employee->id, 'department_id' => $employee->department_id,
            'leave_type_id' => $typeId, 'days_requested' => 3, 'reason' => 'Rest', 'status' => 'hr_approved',
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
            'user_id' => $hr->id,
        ]);

        Sanctum::actingAs($hr);
        $row = collect($this->getJson('/api/v1/employees?per_page=50')->json('data'))->firstWhere('uuid', $employee->uuid);

        $this->assertSame(['type' => 'Annual', 'until' => now()->addDay()->toDateString()], $row['on_leave']);
    }
}
