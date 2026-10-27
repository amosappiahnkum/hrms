<?php

namespace Tests\Feature;

use App\Models\Config\Department;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Services\Org\ReportingLine;
use App\Services\Payroll\ApprovalWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/**
 * Reporting lines: the supervisor is the head of the employee's unit unless "Reports to" says otherwise; heads
 * above follow the department tree. Directorate › Engineering › Inspection unit (and a unit with no head).
 */
class ReportingLineTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $director;
    private User $engHead;
    private User $unitHead;
    private Department $directorate;
    private Department $engineering;
    private Department $inspection;
    private Department $headless;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        $this->director = $this->userWithRole('hod');
        $this->engHead = $this->userWithRole('hod');
        $this->unitHead = $this->userWithRole('hod');
        $this->directorate = Department::create(['name' => 'Technical Directorate', 'hod' => $this->director->employee_id]);
        $this->engineering = Department::create(['name' => 'Engineering', 'hod' => $this->engHead->employee_id, 'parent_department_id' => $this->directorate->id]);
        $this->inspection = Department::create(['name' => 'Inspection', 'hod' => $this->unitHead->employee_id, 'parent_department_id' => $this->engineering->id]);
        $this->headless = Department::create(['name' => 'Stores', 'parent_department_id' => $this->engineering->id]);
        $this->director->employee->update(['department_id' => $this->directorate->id]);
        $this->engHead->employee->update(['department_id' => $this->engineering->id]);
        $this->unitHead->employee->update(['department_id' => $this->inspection->id]);
    }

    private function staffIn(Department $department): Employee
    {
        $employee = $this->userWithRole('staff')->employee;
        $employee->update(['department_id' => $department->id]);

        return $employee->fresh();
    }

    private function line(): ReportingLine
    {
        return app(ReportingLine::class);
    }

    public function test_the_supervisor_is_the_head_of_the_unit_walking_up_past_units_with_no_head(): void
    {
        $officer = $this->staffIn($this->inspection);
        $storekeeper = $this->staffIn($this->headless);

        $this->assertSame($this->unitHead->employee_id, $this->line()->supervisorOf($officer)->id);
        $this->assertSame($this->engHead->employee_id, $this->line()->supervisorOf($storekeeper)->id); // Stores has no head
        $this->assertSame($this->engHead->employee_id, $this->line()->supervisorOf($this->unitHead->employee->fresh())->id);
        $this->assertSame($this->director->employee_id, $this->line()->supervisorOf($this->engHead->employee->fresh())->id);
        $this->assertNull($this->line()->supervisorOf($this->director->employee->fresh()));

        // Heads above, nearest first; stopping after a number.
        $this->assertSame(
            [$this->unitHead->employee_id, $this->engHead->employee_id, $this->director->employee_id],
            $this->line()->headsAbove($officer)->pluck('id')->all(),
        );
        $this->assertSame([$this->unitHead->employee_id, $this->engHead->employee_id], $this->line()->headsAbove($officer, ['stop' => 'count', 'count' => 2])->pluck('id')->all());
        $this->assertSame([$this->director->employee_id], $this->line()->headsAbove($this->engHead->employee->fresh())->pluck('id')->all());
    }

    public function test_reports_to_overrides_the_default_and_moves_them_between_teams(): void
    {
        $officer = $this->staffIn($this->inspection);
        $lead = $this->staffIn($this->inspection);
        $this->assertTrue($this->line()->reportsOf($this->unitHead->employee->fresh())->pluck('id')->contains($officer->id));

        $this->line()->setReportsTo($officer, $lead);
        $this->assertSame($lead->id, $this->line()->supervisorOf($officer->fresh())->id);
        $this->assertSame([$officer->id], $this->line()->reportsOf($lead)->pluck('id')->all());
        $this->assertFalse($this->line()->reportsOf($this->unitHead->employee->fresh())->pluck('id')->contains($officer->id));
        // The chain of heads still follows the department.
        $this->assertSame($this->unitHead->employee_id, $this->line()->headsAbove($officer->fresh())->first()->id);

        // Changing it replaces it; clearing returns to the head of the unit.
        $this->line()->setReportsTo($officer, $this->engHead->employee);
        $this->assertSame($this->engHead->employee_id, $officer->fresh()->reports_to_id);
        $this->line()->setReportsTo($officer, null);
        $this->assertSame($this->unitHead->employee_id, $this->line()->supervisorOf($officer->fresh())->id);

        // No loops, and nobody reports to themselves.
        $this->line()->setReportsTo($lead, $officer);
        $this->expectException(\App\Exceptions\UserFacingException::class);
        $this->line()->setReportsTo($officer, $lead);
    }

    public function test_hr_sets_reports_to_and_sees_the_line(): void
    {
        $officer = $this->staffIn($this->inspection);
        $lead = $this->staffIn($this->inspection);
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['view-employee', 'edit-employee']);
        Sanctum::actingAs($hr);

        $this->getJson("/api/v1/employees/{$officer->uuid}/reporting-line")->assertOk()
            ->assertJsonPath('data.reports_to', null)
            ->assertJsonPath('data.supervisor.uuid', $this->unitHead->employee->uuid)
            ->assertJsonPath('data.supervisor.by_default', true);

        $this->putJson("/api/v1/employees/{$officer->uuid}/reports-to", ['supervisor_uuid' => $officer->uuid])->assertStatus(422);
        $this->putJson("/api/v1/employees/{$officer->uuid}/reports-to", ['supervisor_uuid' => $lead->uuid])->assertOk()
            ->assertJsonPath('data.supervisor.uuid', $lead->uuid)->assertJsonPath('data.supervisor.by_default', false)
            ->assertJsonPath('data.unit_head.uuid', $this->unitHead->employee->uuid);
        $this->getJson("/api/v1/employees/{$lead->uuid}/reporting-line")->assertJsonPath('data.direct_reports.0.uuid', $officer->uuid)
            ->assertJsonPath('data.direct_reports.0.by_default', false);
        $this->putJson("/api/v1/employees/{$officer->uuid}/reports-to", ['supervisor_uuid' => null])->assertOk()
            ->assertJsonPath('data.supervisor.by_default', true);

        Sanctum::actingAs($this->userWithRole('staff'));
        $this->putJson("/api/v1/employees/{$officer->uuid}/reports-to", ['supervisor_uuid' => $lead->uuid])->assertForbidden();
        $this->getJson("/api/v1/employees/{$officer->uuid}/reporting-line")->assertForbidden(); // someone else's
    }

    public function test_employees_see_who_they_report_to_but_cant_change_it(): void
    {
        $officer = $this->staffIn($this->inspection);
        Sanctum::actingAs(User::where('employee_id', $officer->id)->sole());

        $this->getJson("/api/v1/employees/{$officer->uuid}/reporting-line")->assertOk()
            ->assertJsonPath('data.supervisor.uuid', $this->unitHead->employee->uuid)->assertJsonPath('data.supervisor.by_default', true);
        $this->putJson("/api/v1/employees/{$officer->uuid}/reports-to", ['supervisor_uuid' => null])->assertForbidden();
    }

    public function test_supervisor_steps_in_approval_workflows_reach_the_supervisor(): void
    {
        $officer = $this->staffIn($this->inspection);
        $step = ['approver_type' => 'supervisor'];
        $workflows = app(ApprovalWorkflowService::class);

        $this->assertSame([$this->unitHead->id], $workflows->resolve($step, $officer)->pluck('id')->all());
        $lead = $this->userWithRole('staff');
        $lead->employee->update(['department_id' => $this->inspection->id]);
        $this->line()->setReportsTo($officer, $lead->employee->fresh());
        $this->assertSame([$lead->id], $workflows->resolve($step, $officer->fresh())->pluck('id')->all());
    }

    public function test_existing_supervisor_lines_move_to_the_reports_to_column(): void
    {
        $migration = require database_path('migrations/2026_10_16_120000_replace_employee_supervisors_with_reports_to.php');
        $migration->down(); // back to the old table
        $officer = $this->staffIn($this->inspection);
        $other = $this->staffIn($this->inspection);
        $row = fn (int $employee, int $supervisor, array $extra = []) => \Illuminate\Support\Facades\DB::table('employee_supervisors')->insert($extra + [
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'employee_id' => $employee, 'supervisor_id' => $supervisor,
            'report_method' => 'Direct', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row($officer->id, $this->director->employee_id, ['deleted_at' => now()]); // an old line, removed
        $row($officer->id, $this->engHead->employee_id);                         // the one in force
        $row($other->id, $this->engHead->employee_id, ['report_method' => 'In-Direct']);

        $migration->up();

        $this->assertSame($this->engHead->employee_id, $officer->fresh()->reports_to_id);
        $this->assertNull($other->fresh()->reports_to_id); // In-Direct lines aren't supervisors
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('employee_supervisors'));
    }
}
