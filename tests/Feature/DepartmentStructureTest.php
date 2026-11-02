<?php

namespace Tests\Feature;

use App\Models\Config\Department;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Services\Org\ReportingLine;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Department levels, sub-units as sub-departments, and "department X" including its sub-departments. */
class DepartmentStructureTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['view-employee', 'edit-department', 'add-department']);
        app(SettingService::class)->set('org.department_levels', ['Directorate', 'Department', 'Unit']);
    }

    private function staffIn(Department $department): Employee
    {
        $employee = $this->userWithRole('staff')->employee;
        $employee->update(['department_id' => $department->id]);

        return $employee->fresh();
    }

    public function test_levels_are_named_and_given_to_departments(): void
    {
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/departments/levels')->assertOk()->assertJsonPath('data', ['Directorate', 'Department', 'Unit']);

        // Department resources aren't wrapped in "data".
        $uuid = $this->postJson('/api/v1/departments', ['name' => 'Technical', 'level' => 0])->assertSuccessful()
            ->assertJsonPath('level_name', 'Directorate')->json('uuid');
        $this->postJson('/api/v1/departments', ['name' => 'Engineering', 'level' => 5])->assertJsonValidationErrors('level');
        $this->postJson('/api/v1/departments', ['name' => 'Inspection', 'level' => 2, 'parent_department_id' => $uuid])->assertSuccessful();

        // Renaming is fine; removing a level a department is at is refused.
        $this->putJson('/api/v1/departments/levels', ['levels' => ['Directorate', 'Department', 'Section']])->assertOk();
        $this->assertSame('Section', Department::where('name', 'Inspection')->first()->levelName());
        $this->putJson('/api/v1/departments/levels', ['levels' => ['Directorate', 'Department']])->assertStatus(422);
        $this->putJson('/api/v1/departments/levels', ['levels' => ['A', 'A']])->assertJsonValidationErrors('levels.1');

        Sanctum::actingAs($this->userWithRole('staff'));
        $this->putJson('/api/v1/departments/levels', ['levels' => ['X']])->assertForbidden();
    }

    public function test_an_approval_chain_stops_at_a_level(): void
    {
        $board = Department::create(['name' => 'Board', 'hod' => $this->userWithRole('staff')->employee_id]);
        $directorate = Department::create(['name' => 'Technical', 'level' => 0, 'parent_department_id' => $board->id, 'hod' => $this->userWithRole('staff')->employee_id]);
        $department = Department::create(['name' => 'Engineering', 'level' => 1, 'parent_department_id' => $directorate->id, 'hod' => $this->userWithRole('staff')->employee_id]);
        $unit = Department::create(['name' => 'Inspection', 'level' => 2, 'parent_department_id' => $department->id, 'hod' => $this->userWithRole('staff')->employee_id]);
        $officer = $this->staffIn($unit);
        $line = app(ReportingLine::class);

        $this->assertSame(['Inspection', 'Engineering'], $line->chainAbove($officer, ['stop' => 'level', 'level' => 1])->map(fn ($l) => $l['department']->name)->all());
        $this->assertSame(['Inspection', 'Engineering', 'Technical'], $line->chainAbove($officer, ['stop' => 'level', 'level' => 0])->map(fn ($l) => $l['department']->name)->all());
        $this->assertSame(['Inspection', 'Engineering', 'Technical', 'Board'], $line->chainAbove($officer)->map(fn ($l) => $l['department']->name)->all());

        // A workflow step can say so.
        $this->hr->givePermissionTo('configure-payroll');
        \App\Models\Config\Setting::where('key', 'features.payroll.overtime')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/approval-workflows', [
            'process' => 'overtime', 'name' => 'Overtime', 'steps' => [['name' => 'Heads', 'approver_type' => 'heads_chain', 'approver_value' => ['stop' => 'level', 'level' => 0]]],
        ])->assertCreated()->assertJsonPath('data.steps.0.approver_value.level', 0);
        $this->postJson('/api/v1/payroll/approval-workflows', [
            'process' => 'overtime', 'name' => 'Overtime 2', 'steps' => [['name' => 'Heads', 'approver_type' => 'heads_chain', 'approver_value' => ['stop' => 'level', 'level' => 7]]],
        ])->assertJsonValidationErrors('steps.0.approver_value');
    }

    public function test_a_department_filter_includes_its_sub_departments(): void
    {
        $engineering = Department::create(['name' => 'Engineering']);
        $inspection = Department::create(['name' => 'Inspection', 'parent_department_id' => $engineering->id]);
        $other = Department::create(['name' => 'Finance']);
        $inUnit = $this->staffIn($inspection);
        $inDepartment = $this->staffIn($engineering);
        $elsewhere = $this->staffIn($other);

        $this->assertEqualsCanonicalizing([$engineering->id, $inspection->id], Department::subtreeIds($engineering->id));
        $ids = Employee::query()->department((string) $engineering->id)->pluck('id');
        $this->assertTrue($ids->contains($inUnit->id) && $ids->contains($inDepartment->id));
        $this->assertFalse($ids->contains($elsewhere->id));

        Sanctum::actingAs($this->hr);
        $listed = collect($this->getJson("/api/v1/employees?department={$engineering->id}&per_page=50")->assertOk()->json('data'))->pluck('uuid');
        $this->assertTrue($listed->contains($inUnit->uuid));
        $this->assertFalse($listed->contains($elsewhere->uuid));
    }

    public function test_sub_units_in_use_become_sub_departments(): void
    {
        $migration = require database_path('migrations/2026_10_16_130000_add_department_levels_and_move_sub_units.php');
        $migration->down();
        $finance = Department::create(['name' => 'Finance']);
        $works = Department::create(['name' => 'Works']);
        Department::create(['name' => 'ICT Unit']); // the name is taken elsewhere
        $unit = DB::table('sub_units')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'ICT Unit', 'created_at' => now(), 'updated_at' => now()]);
        $a = $this->staffIn($finance);
        $b = $this->staffIn($finance);
        $c = $this->staffIn($works);
        $untouched = $this->staffIn($works);
        foreach ([$a, $b, $c] as $e) {
            $e->jobDetail()->update(['sub_unit_id' => $unit]);
        }

        $migration->up();

        $financeIct = Department::where('parent_department_id', $finance->id)->sole();
        $worksIct = Department::where('parent_department_id', $works->id)->sole();
        $this->assertSame(['ICT Unit (Finance)', 'ICT Unit (Works)'], [$financeIct->name, $worksIct->name]);
        $this->assertSame([$financeIct->id, $financeIct->id, $worksIct->id, $works->id], [$a->fresh()->department_id, $b->fresh()->department_id, $c->fresh()->department_id, $untouched->fresh()->department_id]);
        $this->assertSame(2, DB::table('sub_unit_moves')->count());

        // Undone on rollback.
        $migration->down();
        $this->assertSame([$finance->id, $works->id], [$a->fresh()->department_id, $c->fresh()->department_id]);
        $this->assertSame(0, Department::where('parent_department_id', $finance->id)->count());
        $this->assertFalse(Schema::hasTable('sub_unit_moves'));
        $migration->up();
    }
}
