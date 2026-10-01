<?php

namespace Tests\Feature;

use App\Models\Config\Department;
use App\Models\EmployeeSupervisor;
use App\Models\Position;
use App\Models\SelfService\Employee;
use App\Models\TrainingPlan\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class CompetencyTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private Position $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccess(['competency.enabled', 'training_plan.enabled']);
        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['view-competencies', 'assess-competencies', 'manage-competencies']);
        Sanctum::actingAs($this->hr);

        $this->officer = Position::create(['name' => 'HR Officer']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function competency(string $name, string $group = 'process'): string
    {
        return $this->postJson('/api/v1/competency/competencies', ['name' => $name, 'group' => $group])
            ->assertCreated()->json('data.uuid');
    }

    /** @param array<string,int> $levels competency uuid => required level */
    private function require(Position $position, array $levels): void
    {
        $this->putJson("/api/v1/competency/positions/{$position->uuid}", [
            'requirements' => collect($levels)->map(fn ($l, $uuid) => ['competency_uuid' => $uuid, 'required_level' => $l])->values()->all(),
        ])->assertOk();
    }

    private function employeeIn(Position $position, ?Department $department = null): Employee
    {
        $employee = $this->userWithRole('staff')->employee;
        $employee->jobDetail()->update(['position_id' => $position->id]);
        if ($department) {
            $employee->update(['department_id' => $department->id]);
        }

        return $employee->fresh();
    }

    /** @param array<string,int|null> $levels */
    private function assess(Employee $employee, array $levels): string
    {
        $uuid = $this->postJson("/api/v1/competency/employees/{$employee->uuid}/assessments")->assertOk()->json('data.uuid');
        $this->putJson("/api/v1/competency/assessments/{$uuid}", [
            'ratings' => collect($levels)->map(fn ($l, $c) => ['competency_uuid' => $c, 'level' => $l, 'evidence' => 'Observed'])->values()->all(),
        ])->assertOk();
        $this->postJson("/api/v1/competency/assessments/{$uuid}/complete")->assertOk();

        return $uuid;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_positions_require_competency_levels_and_requirements_can_be_replaced(): void
    {
        $a = $this->competency('HR policies');
        $b = $this->competency('Communication', 'behavioural');
        $this->require($this->officer, [$a => 3, $b => 4]);
        $this->require($this->officer, [$a => 2]);

        $this->getJson("/api/v1/competency/positions/{$this->officer->uuid}")->assertOk()
            ->assertJsonCount(1, 'data.requirements')
            ->assertJsonPath('data.requirements.0.required_level', 2);

        // Adding it back restores the earlier row instead of duplicating it.
        $this->require($this->officer, [$a => 2, $b => 3]);
        $this->getJson('/api/v1/competency/positions')->assertOk()->assertJsonPath('data.data.0.requirements_count', 2);
    }

    public function test_an_assessment_records_current_levels_and_shows_the_gaps(): void
    {
        $policies = $this->competency('HR policies');
        $labour = $this->competency('Labour law', 'legal');
        $this->require($this->officer, [$policies => 3, $labour => 3]);
        $employee = $this->employeeIn($this->officer);

        $draft = $this->postJson("/api/v1/competency/employees/{$employee->uuid}/assessments")->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(2, 'data.ratings')
            ->json('data.uuid');

        // Required competencies need a level before completing.
        $this->postJson("/api/v1/competency/assessments/{$draft}/complete")->assertStatus(422);

        $this->putJson("/api/v1/competency/assessments/{$draft}", ['ratings' => [
            ['competency_uuid' => $policies, 'level' => 3],
            ['competency_uuid' => $labour, 'level' => 1, 'evidence' => 'Needs ADR knowledge'],
        ]])->assertOk();
        $this->postJson("/api/v1/competency/assessments/{$draft}/complete")->assertOk()
            ->assertJsonPath('data.next_review_on', now()->addMonthsNoOverflow(12)->toDateString());

        // Completed assessments are locked.
        $this->putJson("/api/v1/competency/assessments/{$draft}", ['comment' => 'x'])->assertStatus(422);

        $this->getJson("/api/v1/competency/employees/{$employee->uuid}")->assertOk()
            ->assertJsonPath('data.summary', ['required' => 2, 'met' => 1, 'gaps' => 1, 'not_rated' => 0]);

        $this->getJson('/api/v1/competency/gaps')->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.competency.uuid', $labour)
            ->assertJsonPath('data.data.0.gap', 2);

        $matrix = $this->getJson('/api/v1/competency/matrix')->assertOk()->json('data');
        $this->assertCount(2, $matrix['columns']);
        $row = collect($matrix['data'])->firstWhere('employee.uuid', $employee->uuid);
        $this->assertSame(1, $row['gaps']);
        $this->assertSame(['r' => 3, 'l' => 1], $row['cells'][$labour]);

        $this->getJson('/api/v1/competency/summary')->assertOk()
            ->assertJsonPath('data.assessed', 1)
            ->assertJsonPath('data.gaps', 1)
            ->assertJsonPath('data.gaps_unplanned', 1);
    }

    public function test_the_latest_assessment_is_the_current_state_and_a_new_one_starts_from_it(): void
    {
        $policies = $this->competency('HR policies');
        $this->require($this->officer, [$policies => 3]);
        $employee = $this->employeeIn($this->officer);

        $this->assess($employee, [$policies => 1]);
        $this->assertSame(1, $this->getJson('/api/v1/competency/gaps')->json('data.meta.total'));

        $next = $this->postJson("/api/v1/competency/employees/{$employee->uuid}/assessments")->assertOk();
        $next->assertJsonPath('data.ratings.0.level', 1); // carried over
        $this->putJson("/api/v1/competency/assessments/{$next->json('data.uuid')}", ['ratings' => [['competency_uuid' => $policies, 'level' => 3]]])->assertOk();
        $this->postJson("/api/v1/competency/assessments/{$next->json('data.uuid')}/complete")->assertOk();

        $this->assertSame(0, $this->getJson('/api/v1/competency/gaps')->json('data.meta.total'));
        $this->getJson("/api/v1/competency/employees/{$employee->uuid}")->assertJsonCount(2, 'data.history');
    }

    public function test_gaps_get_development_actions_and_a_training_action_links_to_the_plan(): void
    {
        $labour = $this->competency('Labour law', 'legal');
        $this->require($this->officer, [$labour => 3]);
        $employee = $this->employeeIn($this->officer);
        $this->assess($employee, [$labour => 1]);

        $coaching = $this->postJson('/api/v1/competency/actions', [
            'employee_uuid' => $employee->uuid, 'competency_uuid' => $labour, 'method' => 'coaching', 'due_on' => '2026-12-01',
        ])->assertCreated()->json('data.uuid');

        $this->getJson('/api/v1/competency/summary')->assertJsonPath('data.gaps_unplanned', 0);
        $this->getJson('/api/v1/competency/gaps')->assertJsonPath('data.data.0.actions.0.method.value', 'coaching');

        $this->putJson("/api/v1/competency/actions/{$coaching}", ['status' => 'done', 'outcome' => 'Handled two cases'])->assertOk()
            ->assertJsonPath('data.completed_on', now()->toDateString());

        // A training action is linked to the employee's line in a training plan.
        $training = $this->postJson('/api/v1/competency/actions', [
            'employee_uuid' => $employee->uuid, 'competency_uuid' => $labour, 'method' => 'training',
        ])->assertCreated()->json('data.uuid');
        $plan = TrainingPlan::create(['year' => 2026, 'title' => '2026 plan', 'approval_status' => 'draft']);
        $item = $plan->items()->create([
            'employee_id' => $employee->id, 'title' => 'Ghana Labour Act', 'nature' => 'compulsory', 'category' => 'non_technical',
            'quarter' => 'Q4', 'delivery' => 'external', 'cost' => 0, 'approval_status' => 'draft',
        ]);
        $other = $plan->items()->create([
            'employee_id' => $this->userWithRole('staff')->employee_id, 'title' => 'Ghana Labour Act', 'nature' => 'compulsory',
            'category' => 'non_technical', 'quarter' => 'Q4', 'delivery' => 'external', 'cost' => 0, 'approval_status' => 'draft',
        ]);

        $this->putJson("/api/v1/competency/actions/{$training}", ['training_plan_item_uuid' => $other->uuid])->assertStatus(422);
        $this->putJson("/api/v1/competency/actions/{$training}", ['training_plan_item_uuid' => $item->uuid])->assertOk()
            ->assertJsonPath('data.training.title', 'Ghana Labour Act');
    }

    public function test_team_leaders_see_and_assess_their_team_without_any_permission(): void
    {
        $policies = $this->competency('HR policies');
        $this->require($this->officer, [$policies => 3]);

        $hod = $this->userWithRole('hod');
        $admin = Department::create(['name' => 'Admin', 'hod' => $hod->employee_id]);
        $records = Department::create(['name' => 'Records', 'parent_department_id' => $admin->id]);
        $finance = Department::create(['name' => 'Finance']);
        $inAdmin = $this->employeeIn($this->officer, $admin);
        $inRecords = $this->employeeIn($this->officer, $records);
        $inFinance = $this->employeeIn($this->officer, $finance);

        Sanctum::actingAs($hod);
        $this->postJson("/api/v1/competency/employees/{$inAdmin->uuid}/assessments")->assertOk();
        $this->postJson("/api/v1/competency/employees/{$inRecords->uuid}/assessments")->assertOk(); // sub-department
        $this->postJson("/api/v1/competency/employees/{$inFinance->uuid}/assessments")->assertForbidden();
        $this->getJson("/api/v1/competency/employees/{$inFinance->uuid}")->assertForbidden();
        $this->assertSame(2, $this->getJson('/api/v1/competency/matrix')->json('data.meta.total'));
        $this->putJson("/api/v1/competency/positions/{$this->officer->uuid}", ['requirements' => []])->assertForbidden();

        // Nobody assesses themselves.
        $hod->employee->jobDetail()->update(['position_id' => $this->officer->id]);
        $this->postJson("/api/v1/competency/employees/{$hod->employee->uuid}/assessments")->assertForbidden();

        // A supervisor leads their direct reports.
        $supervisor = $this->userWithRole('staff');
        EmployeeSupervisor::create(['supervisor_id' => $supervisor->employee_id, 'employee_id' => $inFinance->id]);
        Sanctum::actingAs($supervisor);
        $this->postJson("/api/v1/competency/employees/{$inFinance->uuid}/assessments")->assertOk();
        $this->assertSame(1, $this->getJson('/api/v1/competency/matrix')->json('data.meta.total'));
    }

    public function test_permission_holders_see_everyone_and_others_only_their_own_profile(): void
    {
        $policies = $this->competency('HR policies');
        $this->require($this->officer, [$policies => 3]);
        $someone = $this->employeeIn($this->officer, Department::create(['name' => 'Finance']));

        // View-only: the whole organisation, but no rating.
        $auditor = $this->userWithRole('staff');
        $auditor->givePermissionTo('view-competencies');
        Sanctum::actingAs($auditor);
        $this->getJson("/api/v1/competency/employees/{$someone->uuid}")->assertOk();
        $this->postJson("/api/v1/competency/employees/{$someone->uuid}/assessments")->assertForbidden();

        // Neither a permission nor a team: only their own profile.
        Sanctum::actingAs(User::where('employee_id', $someone->id)->first());
        $this->getJson('/api/v1/competency/mine')->assertOk()->assertJsonPath('data.employee.uuid', $someone->uuid);
        $this->getJson('/api/v1/competency/matrix')->assertForbidden();
        $this->getJson('/api/v1/competency/options')->assertForbidden();
    }
}
