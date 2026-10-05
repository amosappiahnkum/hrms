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
        $this->hr->givePermissionTo(['view-competencies', 'assess-competencies', 'manage-competencies', 'grant-authorizations']);
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
            ->assertJsonPath('data.summary', ['required' => 2, 'met' => 1, 'gaps' => 1, 'not_rated' => 0, 'certification_gaps' => 0]);

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

        // Finishing takes an effectiveness check; it can't just be marked done.
        $this->putJson("/api/v1/competency/actions/{$coaching}", ['status' => 'done'])->assertUnprocessable();
        $this->postJson("/api/v1/competency/actions/{$coaching}/evaluate", [
            'result' => 'partially', 'verified_level' => 2, 'evidence' => 'Supervisor observed two hearings', 'outcome' => 'Handled two cases',
        ])->assertOk()->assertJsonPath('data.completed_on', now()->toDateString())->assertJsonPath('data.status.value', 'done');

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

    public function test_a_training_action_can_be_linked_when_added_and_names_the_assessment_as_supporting_record(): void
    {
        $labour = $this->competency('Labour law', 'legal');
        $this->require($this->officer, [$labour => 3]);
        $employee = $this->employeeIn($this->officer);
        $this->assess($employee, [$labour => 1]);

        $plan = TrainingPlan::create(['year' => 2026, 'title' => '2026 plan', 'approval_status' => 'draft']);
        $line = fn (array $extra = []) => $plan->items()->create($extra + [
            'employee_id' => $employee->id, 'title' => 'Ghana Labour Act', 'nature' => 'compulsory', 'category' => 'non_technical',
            'quarter' => 'Q4', 'delivery' => 'external', 'cost' => 0, 'approval_status' => 'draft',
            'source_of_need' => 'competency_gap_analysis', 'supporting_record' => 'Competency Matrix',
        ]);
        $draft = $line();

        $this->postJson('/api/v1/competency/actions', [
            'employee_uuid' => $employee->uuid, 'competency_uuid' => $labour, 'method' => 'training',
            'status' => 'in_progress', 'training_plan_item_uuid' => $draft->uuid,
        ])->assertCreated()
            ->assertJsonPath('data.training.title', 'Ghana Labour Act')
            ->assertJsonPath('data.status.value', 'in_progress');
        $this->assertSame('Competency Matrix – assessment of ' . now()->format('j M Y'), $draft->fresh()->supporting_record);

        // Someone else's line is refused, and nothing is created.
        $other = $plan->items()->create([
            'employee_id' => $this->userWithRole('staff')->employee_id, 'title' => 'Ghana Labour Act', 'nature' => 'compulsory',
            'category' => 'non_technical', 'quarter' => 'Q4', 'delivery' => 'external', 'cost' => 0, 'approval_status' => 'draft',
        ]);
        $this->postJson('/api/v1/competency/actions', [
            'employee_uuid' => $employee->uuid, 'competency_uuid' => $labour, 'method' => 'training', 'training_plan_item_uuid' => $other->uuid,
        ])->assertStatus(422);
        $this->assertSame(1, \App\Models\Competency\DevelopmentAction::count());

        // An approved line keeps its supporting record: changing it would need re-approval.
        $approved = $line(['approval_status' => 'approved', 'title' => 'Labour Act refresher']);
        $this->postJson('/api/v1/competency/actions', [
            'employee_uuid' => $employee->uuid, 'competency_uuid' => $labour, 'method' => 'training', 'training_plan_item_uuid' => $approved->uuid,
        ])->assertCreated();
        $this->assertSame('Competency Matrix', $approved->fresh()->supporting_record);
    }

    public function test_catalogue_trainings_list_the_competencies_they_develop_and_gaps_get_course_suggestions(): void
    {
        $ut = $this->competency('Ultrasonic Testing Level 2', 'technical');
        $reporting = $this->competency('Technical report writing');

        $course = $this->postJson('/api/v1/training-plan/catalogue', [
            'title' => 'UT Level 2 (ISO 9712)', 'nature' => 'technical_development',
            'competencies' => [['competency_uuid' => $ut, 'target_level' => 3], ['competency_uuid' => $reporting, 'target_level' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.competencies.0.name', 'Technical report writing')
            ->assertJsonPath('data.competencies.1.target_level', 3)
            ->json('data.uuid');

        $this->postJson('/api/v1/training-plan/catalogue', [
            'title' => 'Bad', 'nature' => 'technical_development',
            'competencies' => [['competency_uuid' => $ut, 'target_level' => 3], ['competency_uuid' => $ut, 'target_level' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['competencies.1.competency_uuid', 'competencies.1.target_level']);

        // Saving replaces the links; leaving them out keeps them; a removed link is revived, not duplicated.
        $this->putJson("/api/v1/training-plan/catalogue/{$course}", ['competencies' => [['competency_uuid' => $ut, 'target_level' => 4]]])
            ->assertOk()->assertJsonCount(1, 'data.competencies')->assertJsonPath('data.competencies.0.target_level', 4);
        $this->putJson("/api/v1/training-plan/catalogue/{$course}", ['trainer' => 'Bultest'])->assertOk()->assertJsonCount(1, 'data.competencies');
        $this->putJson("/api/v1/training-plan/catalogue/{$course}", ['competencies' => [
            ['competency_uuid' => $ut, 'target_level' => 4], ['competency_uuid' => $reporting, 'target_level' => 2],
        ]])->assertOk()->assertJsonCount(2, 'data.competencies');
        $this->assertSame(2, \App\Models\TrainingPlan\TrainingCatalogueCompetency::withTrashed()->count());

        $this->getJson("/api/v1/training-plan/catalogue?competency_uuid={$reporting}")->assertOk()->assertJsonCount(1, 'data');

        // Suggestions for a gap: the course reaching the higher level first; removed courses left out.
        $intro = $this->postJson('/api/v1/training-plan/catalogue', [
            'title' => 'UT Level 1', 'nature' => 'technical_development', 'competencies' => [['competency_uuid' => $ut, 'target_level' => 2]],
        ])->assertCreated()->json('data.uuid');
        $old = $this->postJson('/api/v1/training-plan/catalogue', [
            'title' => 'UT (old syllabus)', 'nature' => 'technical_development', 'competencies' => [['competency_uuid' => $ut, 'target_level' => 4]],
        ])->assertCreated()->json('data.uuid');
        $this->deleteJson("/api/v1/training-plan/catalogue/{$old}")->assertOk();

        $this->getJson("/api/v1/competency/competencies/{$ut}/courses")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.uuid', $course)->assertJsonPath('data.0.target_level', 4)
            ->assertJsonPath('data.1.uuid', $intro);

        \App\Models\Config\Setting::where('key', 'features.training_plan.enabled')->update(['value' => false]);
        app(\App\Services\SettingService::class)->refreshCache();
        $this->getJson("/api/v1/competency/competencies/{$ut}/courses")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_training_needs_from_gaps_are_queued_grouped_and_planned_in_bulk(): void
    {
        $ut = $this->competency('Ultrasonic Testing Level 2', 'technical');
        $reporting = $this->competency('Technical report writing');
        $this->require($this->officer, [$ut => 3, $reporting => 3]);
        $ndt = Department::create(['name' => 'NDT']);
        $lifting = Department::create(['name' => 'Lifting']);
        [$ama, $kofi, $esi] = [$this->employeeIn($this->officer, $ndt), $this->employeeIn($this->officer, $ndt), $this->employeeIn($this->officer, $lifting)];
        foreach ([$ama, $kofi, $esi] as $e) {
            $this->assess($e, [$ut => 1, $reporting => 2]);
        }
        $this->getJson('/api/v1/competency/gaps?unplanned=1')->assertJsonPath('data.meta.total', 6);

        $course = $this->postJson('/api/v1/training-plan/catalogue', [
            'title' => 'UT Level 2 (ISO 9712)', 'nature' => 'technical_development', 'estimated_cost' => 1500,
            'competencies' => [['competency_uuid' => $ut, 'target_level' => 3]],
        ])->assertCreated()->json('data.uuid');

        $action = fn ($e, $c, $method = 'training') => $this->postJson('/api/v1/competency/actions', [
            'employee_uuid' => $e->uuid, 'competency_uuid' => $c, 'method' => $method,
        ])->assertCreated()->json('data.uuid');
        $amaUt = $action($ama, $ut);
        $kofiUt = $action($kofi, $ut, 'certification');
        $esiUt = $action($esi, $ut);
        $amaReport = $action($ama, $reporting);
        $action($kofi, $reporting, 'coaching'); // not training: stays with the assessor

        $this->getJson('/api/v1/competency/gaps?unplanned=1')->assertJsonPath('data.meta.total', 1);

        // Grouped by competency (default): biggest group first, with the training to start from.
        $this->getJson('/api/v1/training-plan/needs')->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.groups.0.label', 'Ultrasonic Testing Level 2')
            ->assertJsonPath('data.groups.0.count', 3)
            ->assertJsonPath('data.groups.0.course.uuid', $course)
            ->assertJsonPath('data.groups.0.needs.0.required_level', 3)
            ->assertJsonPath('data.groups.0.needs.0.level', 1)
            ->assertJsonPath('data.groups.1.course', null);

        // By course: needs with no linked training last.
        $this->getJson('/api/v1/training-plan/needs?group_by=course')
            ->assertJsonPath('data.groups.0.key', $course)
            ->assertJsonPath('data.groups.1.key', 'none')
            ->assertJsonPath('data.groups.1.label', 'No catalogue training linked');
        $this->getJson("/api/v1/training-plan/needs?group_by=department&department_uuid={$ndt->uuid}")
            ->assertJsonPath('data.total', 3)->assertJsonCount(1, 'data.groups');

        // Esi already has the course in the plan: her need links to that line instead of a new one.
        $plan = TrainingPlan::create(['year' => 2026, 'title' => '2026 plan', 'approval_status' => 'draft']);
        $existing = $plan->items()->create([
            'employee_id' => $esi->id, 'training_catalogue_item_id' => \App\Models\TrainingPlan\TrainingCatalogueItem::where('uuid', $course)->value('id'),
            'title' => 'UT Level 2 (ISO 9712)', 'nature' => 'technical_development', 'category' => 'technician_technical',
            'quarter' => 'Q1', 'delivery' => 'external', 'cost' => 1500, 'approval_status' => 'draft',
        ]);

        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/needs", [
            'action_uuids' => [$amaUt, $kofiUt, $esiUt], 'training_catalogue_item_uuid' => $course,
            'category' => 'technician_technical', 'quarter' => 'Q3', 'delivery' => 'external',
        ])->assertCreated()
            ->assertJsonCount(2, 'data.created')
            ->assertJsonPath('data.created.0.cost', '1500.00')
            ->assertJsonPath('data.skipped.0.uuid', $esi->uuid);

        $line = $plan->items()->where('employee_id', $ama->id)->firstOrFail();
        $this->assertSame('competency_gap_analysis', $line->source_of_need->value);
        $this->assertSame('Competency Matrix – assessment of ' . now()->format('j M Y'), $line->supporting_record);
        $linked = \App\Models\Competency\DevelopmentAction::whereIn('uuid', [$amaUt, $kofiUt, $esiUt])->get()->keyBy('uuid');
        $this->assertSame($line->id, $linked[$amaUt]->training_plan_item_id);
        $this->assertSame($existing->id, $linked[$esiUt]->training_plan_item_id);
        $this->assertSame('in_progress', $linked[$kofiUt]->status->value);

        $this->getJson('/api/v1/training-plan/needs')->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.groups.0.needs.0.uuid', $amaReport);
        $this->getJson("/api/v1/competency/employees/{$ama->uuid}")
            ->assertJsonFragment(['title' => 'UT Level 2 (ISO 9712)', 'year' => 2026]);

        // Stale selection, a plan closed for changes, and people who don't prepare the plan.
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/needs", [
            'action_uuids' => [$amaUt], 'training_catalogue_item_uuid' => $course, 'category' => 'technician_technical', 'quarter' => 'Q3', 'delivery' => 'external',
        ])->assertStatus(409);
        $plan->update(['approval_status' => 'pending_approval']);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/needs", [
            'action_uuids' => [$amaReport], 'title' => 'Report writing', 'nature' => 'technical_development', 'category' => 'technician_technical', 'quarter' => 'Q3', 'delivery' => 'internal',
        ])->assertStatus(422);
        $this->assertNull(\App\Models\Competency\DevelopmentAction::where('uuid', $amaReport)->value('training_plan_item_id'));

        $assessor = $this->userWithRole('staff');
        $assessor->givePermissionTo('assess-competencies');
        Sanctum::actingAs($assessor);
        $this->getJson('/api/v1/training-plan/needs')->assertForbidden();
    }

    public function test_a_completed_training_awaits_an_effectiveness_check_and_only_a_re_rating_closes_the_gap(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $ut = $this->competency('Ultrasonic Testing Level 2', 'technical');
        $reporting = $this->competency('Technical report writing');
        $this->require($this->officer, [$ut => 3, $reporting => 3]);
        $employee = $this->employeeIn($this->officer);
        $this->assess($employee, [$ut => 1, $reporting => 3]);

        $plan = TrainingPlan::create(['year' => 2026, 'title' => '2026 plan', 'approval_status' => 'approved']);
        $line = fn (string $title) => $plan->items()->create([
            'employee_id' => $employee->id, 'title' => $title, 'nature' => 'technical_development', 'category' => 'technician_technical',
            'quarter' => 'Q3', 'delivery' => 'external', 'cost' => 0, 'approval_status' => 'approved',
        ]);
        $first = $line('UT Level 2');
        $action = $this->postJson('/api/v1/competency/actions', [
            'employee_uuid' => $employee->uuid, 'competency_uuid' => $ut, 'method' => 'training', 'status' => 'in_progress', 'training_plan_item_uuid' => $first->uuid,
        ])->assertCreated()->json('data.uuid');

        // The training is completed: the action waits for its effectiveness check, and the assessor is told.
        $this->putJson("/api/v1/training-plan/items/{$first->uuid}", ['status' => 'completed', 'hours' => 40])->assertOk();
        $this->getJson("/api/v1/competency/employees/{$employee->uuid}")
            ->assertJsonPath('data.actions.0.status.value', 'awaiting_evaluation');
        \Illuminate\Support\Facades\Notification::assertSentTo($this->hr, \App\Notifications\DevelopmentActionEvaluationNotification::class);
        $this->putJson("/api/v1/competency/actions/{$action}", ['status' => 'done'])->assertUnprocessable();

        // "Effective" needs the required level; evidence is required.
        $this->postJson("/api/v1/competency/actions/{$action}/evaluate", ['result' => 'effective', 'verified_level' => 2, 'evidence' => 'Practical test'])->assertStatus(422);
        $this->postJson("/api/v1/competency/actions/{$action}/evaluate", ['result' => 'partially', 'verified_level' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('evidence');

        // Partially effective: finished, the gap narrows but stays open, without a plan.
        $this->postJson("/api/v1/competency/actions/{$action}/evaluate", ['result' => 'partially', 'verified_level' => 2, 'evidence' => 'Practical test: 2 of 3 scans acceptable'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'done')
            ->assertJsonPath('data.effectiveness.result.value', 'partially')
            ->assertJsonPath('data.effectiveness.verified_level', 2);
        $this->postJson("/api/v1/competency/actions/{$action}/evaluate", ['result' => 'partially', 'verified_level' => 2, 'evidence' => 'Again'])->assertStatus(422);

        $profile = $this->getJson("/api/v1/competency/employees/{$employee->uuid}")->assertOk()->json('data');
        $rows = collect($profile['competencies'])->keyBy('competency.name');
        $this->assertSame(2, $rows['Ultrasonic Testing Level 2']['level']);
        $this->assertSame(3, $rows['Technical report writing']['level'], 'other levels carried forward');
        $this->assertStringContainsString('Effectiveness check', $profile['latest_assessment']['comment']);
        $this->getJson('/api/v1/competency/gaps?unplanned=1')->assertJsonPath('data.meta.total', 1);

        // A second training fails: the action goes back to planning and to the training team's queue.
        $second = $line('UT Level 2 (resit)');
        $retry = $this->postJson('/api/v1/competency/actions', [
            'employee_uuid' => $employee->uuid, 'competency_uuid' => $ut, 'method' => 'training', 'training_plan_item_uuid' => $second->uuid,
        ])->assertCreated()->json('data.uuid');
        $this->putJson("/api/v1/training-plan/items/{$second->uuid}", ['status' => 'failed', 'comment' => 'Did not pass the practical'])->assertOk();
        $this->getJson("/api/v1/competency/employees/{$employee->uuid}")
            ->assertJsonPath('data.actions.0.uuid', $retry)
            ->assertJsonPath('data.actions.0.status.value', 'planned')
            ->assertJsonPath('data.actions.0.training', null);
        $this->getJson('/api/v1/training-plan/needs')->assertJsonPath('data.total', 1);

        // Coaching instead, verified at the required level: the gap closes.
        $this->putJson("/api/v1/competency/actions/{$retry}", ['method' => 'coaching'])->assertOk();
        $this->postJson("/api/v1/competency/actions/{$retry}/evaluate", ['result' => 'effective', 'verified_level' => 3, 'evidence' => 'Signed off three independent scans'])->assertOk();
        $this->getJson('/api/v1/competency/gaps')->assertJsonPath('data.meta.total', 0);
    }

    public function test_evidence_files_attach_to_draft_ratings_and_open_actions_and_carry_forward(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');
        \Illuminate\Support\Facades\Storage::disk('s3')->buildTemporaryUrlsUsing(fn ($path) => "https://files.test/{$path}");
        $file = fn (string $name = 'observation.pdf', int $kb = 120) => \Illuminate\Http\UploadedFile::fake()->create($name, $kb, 'application/pdf');

        $ut = $this->competency('Ultrasonic Testing Level 2', 'technical');
        $this->require($this->officer, [$ut => 3]);
        $employee = $this->employeeIn($this->officer);

        $draft = $this->postJson("/api/v1/competency/employees/{$employee->uuid}/assessments")->assertOk()->json('data');
        $rating = $draft['ratings'][0]['uuid'];

        $upload = fn (string $target, string $uuid, $f = null) => $this->post('/api/v1/competency/evidence', ['target' => $target, 'target_uuid' => $uuid, 'file' => $f ?? $file()], ['Accept' => 'application/json']);
        $evidence = $upload('rating', $rating)->assertCreated()->assertJsonPath('data.file_name', 'observation.pdf')->json('data.uuid');
        $upload('rating', $rating, \Illuminate\Http\UploadedFile::fake()->create('tool.exe', 10))->assertUnprocessable()->assertJsonValidationErrors('file');
        $upload('rating', $rating, $file('huge.pdf', 30000))->assertUnprocessable()->assertJsonValidationErrors('file');

        // Removing hides it from the record but keeps the stored file.
        $extra = $upload('rating', $rating, $file('draft-notes.pdf'))->assertCreated()->json('data.uuid');
        $path = \App\Models\Competency\CompetencyEvidenceFile::where('uuid', $extra)->value('file_path');
        $this->deleteJson("/api/v1/competency/evidence/{$extra}")->assertOk();
        \Illuminate\Support\Facades\Storage::disk('s3')->assertExists($path);

        $this->putJson("/api/v1/competency/assessments/{$draft['uuid']}", ['ratings' => [['competency_uuid' => $ut, 'level' => 1, 'evidence' => 'Observed']]])->assertOk();
        $this->postJson("/api/v1/competency/assessments/{$draft['uuid']}/complete")->assertOk();
        $upload('rating', $rating)->assertStatus(422);
        $this->deleteJson("/api/v1/competency/evidence/{$evidence}")->assertStatus(422);

        // The profile shows it; the employee can download their own evidence, other staff can't.
        $this->getJson("/api/v1/competency/employees/{$employee->uuid}")->assertJsonPath('data.competencies.0.files.0.uuid', $evidence);
        Sanctum::actingAs(User::where('employee_id', $employee->id)->firstOrFail());
        $this->getJson("/api/v1/competency/evidence/{$evidence}/download")->assertOk()->assertJsonPath('data.url', fn ($url) => str_starts_with($url, 'https://files.test/competency-evidence/'));
        Sanctum::actingAs($this->userWithRole('staff'));
        $this->getJson("/api/v1/competency/evidence/{$evidence}/download")->assertForbidden();
        $upload('rating', $rating)->assertForbidden();
        Sanctum::actingAs($this->hr);

        // The next assessment starts with the evidence carried forward.
        $this->postJson("/api/v1/competency/employees/{$employee->uuid}/assessments")->assertOk()
            ->assertJsonCount(1, 'data.ratings.0.files')->assertJsonPath('data.ratings.0.files.0.file_name', 'observation.pdf');

        // Actions take files while open (e.g. for the effectiveness check), not once finished.
        $action = $this->postJson('/api/v1/competency/actions', ['employee_uuid' => $employee->uuid, 'competency_uuid' => $ut, 'method' => 'coaching'])->assertCreated()->json('data.uuid');
        $upload('action', $action, $file('practical-test.pdf'))->assertCreated();
        $this->postJson("/api/v1/competency/actions/{$action}/evaluate", ['result' => 'effective', 'verified_level' => 3, 'evidence' => 'Practical test passed'])
            ->assertOk()->assertJsonPath('data.files.0.file_name', 'practical-test.pdf');
        $upload('action', $action)->assertStatus(422);
    }

    public function test_positions_require_certifications_and_expiring_ones_become_renewal_needs(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $safety = $this->competency('Offshore safety', 'ims');
        $bosiet = $this->postJson('/api/v1/competency/certification-types', ['name' => 'BOSIET', 'validity_months' => 48, 'competency_uuid' => $safety])
            ->assertCreated()->assertJsonPath('data.competency.name', 'Offshore safety')->json('data.uuid');
        $compex = $this->postJson('/api/v1/competency/certification-types', ['name' => 'CompEx'])->assertCreated()->json('data.uuid');
        $this->postJson('/api/v1/competency/certification-types', ['name' => 'bosiet'])->assertUnprocessable();

        // BOSIET is mandatory for the position; CompEx only recommended.
        $this->putJson("/api/v1/competency/positions/{$this->officer->uuid}/certifications", ['certifications' => [
            ['certification_type_uuid' => $bosiet, 'mandatory' => true], ['certification_type_uuid' => $compex, 'mandatory' => false],
        ]])->assertOk()->assertJsonPath('data.certifications.0.type.name', 'BOSIET')->assertJsonPath('data.certifications.1.mandatory', false);

        $employee = $this->employeeIn($this->officer);
        $profile = fn () => $this->getJson("/api/v1/competency/employees/{$employee->uuid}")->assertOk()->json('data');
        $this->assertSame(['missing', 'missing'], collect($profile()['certifications'])->pluck('status')->all());
        $this->assertSame(1, $profile()['summary']['certification_gaps'], 'only the mandatory one is a gap');

        $provider = \App\Models\CertificationProvider::create(['name' => 'OPITO centre']);
        $certificate = fn (string $expiry) => \App\Models\EmployeeCertification::create([
            'employee_id' => $employee->id, 'certification_provider_id' => $provider->id,
            'certification_type_id' => \App\Models\Competency\CertificationType::where('uuid', $bosiet)->value('id'),
            'title' => 'BOSIET', 'date_received' => '2023-01-10', 'expiry_date' => $expiry, 'does_not_expire' => false,
            'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'file_size' => 1, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->hr->id,
        ]);

        $certificate(now()->addDays(30)->toDateString());
        $this->assertSame('expiring', $profile()['certifications'][0]['status']);
        $this->assertSame(0, $profile()['summary']['certification_gaps']);
        $this->getJson("/api/v1/competency/matrix?position_uuid={$this->officer->uuid}")->assertJsonPath('data.data.0.certifications.required', 2)
            ->assertJsonPath('data.data.0.certifications.expiring', 1);

        // Expiring within 90 days: a renewal need for the training team, once.
        $this->artisan('certifications:send-expiry-reminders')->assertSuccessful();
        $this->artisan('certifications:send-expiry-reminders')->assertSuccessful();
        $this->getJson('/api/v1/training-plan/needs')->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.groups.0.needs.0.method.value', 'certification')
            ->assertJsonPath('data.groups.0.needs.0.description', fn ($d) => str_starts_with($d, 'Renew BOSIET (expires'));

        // Expired: a gap. Renewed: valid, and no new need.
        $this->travel(31)->days();
        $this->assertSame('expired', $profile()['certifications'][0]['status']);
        $this->assertSame(1, $profile()['summary']['certification_gaps']);
        \App\Models\Competency\DevelopmentAction::query()->update(['status' => 'cancelled']);
        $certificate(now()->addYears(4)->toDateString());
        $this->assertSame('valid', $profile()['certifications'][0]['status']);
        $this->artisan('certifications:send-expiry-reminders')->assertSuccessful();
        $this->getJson('/api/v1/training-plan/needs')->assertJsonPath('data.total', 0);
    }

    public function test_the_authorization_register_follows_competence_and_certificates(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $ut = $this->competency('Ultrasonic Testing Level 2', 'technical');
        $this->require($this->officer, [$ut => 3]);
        $bosiet = $this->postJson('/api/v1/competency/certification-types', ['name' => 'BOSIET'])->assertCreated()->json('data.uuid');

        // Setting up an activity: each requirement is a competency level or a certificate.
        $this->postJson('/api/v1/competency/authorization-activities', ['name' => 'Sign UT reports', 'requirements' => []])->assertUnprocessable();
        $this->postJson('/api/v1/competency/authorization-activities', ['name' => 'X', 'requirements' => [['competency_uuid' => $ut, 'min_level' => 3, 'certification_type_uuid' => $bosiet]]])
            ->assertUnprocessable()->assertJsonValidationErrors('requirements.0');
        $activity = $this->postJson('/api/v1/competency/authorization-activities', [
            'name' => 'Sign UT reports', 'validity_months' => 24, 'position_uuid' => $this->officer->uuid,
            'requirements' => [['competency_uuid' => $ut, 'min_level' => 3], ['certification_type_uuid' => $bosiet]],
        ])->assertCreated()->assertJsonCount(2, 'data.requirements')->json('data.uuid');

        $employee = $this->employeeIn($this->officer);
        $this->assess($employee, [$ut => 2]);
        $leader = $this->userWithRole('staff');
        EmployeeSupervisor::create(['supervisor_id' => $leader->employee_id, 'employee_id' => $employee->id]);

        // Not eligible yet: both requirements unmet.
        $this->getJson("/api/v1/competency/employees/{$employee->uuid}/authorization-options")->assertOk()
            ->assertJsonCount(2, 'data.activities.0.unmet');
        Sanctum::actingAs($leader);
        $this->postJson('/api/v1/competency/authorizations', ['employee_uuid' => $employee->uuid, 'activity_uuid' => $activity])->assertStatus(422);

        Sanctum::actingAs($this->hr);
        $provider = \App\Models\CertificationProvider::create(['name' => 'OPITO centre']);
        $cert = \App\Models\EmployeeCertification::create([
            'employee_id' => $employee->id, 'certification_provider_id' => $provider->id,
            'certification_type_id' => \App\Models\Competency\CertificationType::where('uuid', $bosiet)->value('id'),
            'title' => 'BOSIET', 'date_received' => now()->subYear()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(), 'does_not_expire' => false,
            'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'file_size' => 1, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->hr->id,
        ]);
        Sanctum::actingAs($leader);
        $this->assess($employee, [$ut => 3]);

        // The team leader recommends; they can't grant. Those who grant are told.
        $authorization = $this->postJson('/api/v1/competency/authorizations', ['employee_uuid' => $employee->uuid, 'activity_uuid' => $activity, 'note' => 'Signed 20 reports under supervision'])
            ->assertCreated()->assertJsonPath('data.status.value', 'recommended')->json('data.uuid');
        \Illuminate\Support\Facades\Notification::assertSentTo($this->hr, \App\Notifications\AuthorizationNotification::class);
        $this->postJson('/api/v1/competency/authorizations', ['employee_uuid' => $employee->uuid, 'activity_uuid' => $activity])->assertStatus(409);
        $this->postJson("/api/v1/competency/authorizations/{$authorization}/grant")->assertForbidden();
        $this->postJson('/api/v1/competency/authorizations', ['employee_uuid' => $employee->uuid, 'activity_uuid' => $activity, 'grant' => true])->assertForbidden();

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/competency/authorizations/{$authorization}/grant")->assertOk()
            ->assertJsonPath('data.status.value', 'authorized')
            ->assertJsonPath('data.authorized_by', $this->hr->name)
            ->assertJsonPath('data.valid_until', now()->addMonthsNoOverflow(24)->toDateString());
        $this->getJson('/api/v1/competency/authorizations')->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.recommended_by', $leader->name);
        $this->getJson("/api/v1/competency/employees/{$employee->uuid}")->assertJsonPath('data.authorizations.0.status.value', 'authorized');

        // A lower re-rating suspends it at once; it can't be reinstated until competence is back.
        $this->assess($employee, [$ut => 2]);
        $this->getJson("/api/v1/competency/employees/{$employee->uuid}")
            ->assertJsonPath('data.authorizations.0.status.value', 'suspended')
            ->assertJsonPath('data.authorizations.0.reason', fn ($r) => str_contains($r, 'Ultrasonic Testing Level 2: level 3 needed, rated 2'));
        $this->postJson("/api/v1/competency/authorizations/{$authorization}/grant")->assertStatus(422);
        $this->assess($employee, [$ut => 3]);
        $this->postJson("/api/v1/competency/authorizations/{$authorization}/grant")->assertOk()->assertJsonPath('data.status.value', 'authorized');

        // A certificate lapsing suspends it in the daily check.
        $this->travelTo($cert->expiry_date->copy()->addDay());
        $this->artisan('competency:check-authorizations')->assertSuccessful();
        $this->assertSame('suspended', \App\Models\Competency\EmployeeAuthorization::where('uuid', $authorization)->value('status')->value);

        // Revoked with a reason; then the past grant date passes nothing more.
        $this->postJson("/api/v1/competency/authorizations/{$authorization}/revoke", [])->assertUnprocessable();
        $this->postJson("/api/v1/competency/authorizations/{$authorization}/revoke", ['reason' => 'Left the NDT team'])->assertOk()->assertJsonPath('data.status.value', 'revoked');
        $this->getJson('/api/v1/competency/authorizations')->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/competency/authorizations?status=all')->assertJsonPath('data.meta.total', 1);
    }

    public function test_recommendations_can_be_declined_and_grants_expire_on_their_date(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $ut = $this->competency('Ultrasonic Testing Level 2', 'technical');
        $this->require($this->officer, [$ut => 3]);
        $employee = $this->employeeIn($this->officer);
        $this->assess($employee, [$ut => 3]);
        $activity = $this->postJson('/api/v1/competency/authorization-activities', ['name' => 'Sign UT reports', 'requirements' => [['competency_uuid' => $ut, 'min_level' => 3]]])->json('data.uuid');

        $first = $this->postJson('/api/v1/competency/authorizations', ['employee_uuid' => $employee->uuid, 'activity_uuid' => $activity])->assertCreated()->json('data.uuid');
        $this->postJson("/api/v1/competency/authorizations/{$first}/decline", ['reason' => 'Needs more supervised reports'])->assertOk()->assertJsonPath('data.status.value', 'declined');

        // Granted directly by HR, until a given date; it expires the day after.
        $this->postJson('/api/v1/competency/authorizations', ['employee_uuid' => $employee->uuid, 'activity_uuid' => $activity, 'grant' => true, 'valid_until' => now()->addDays(20)->toDateString()])
            ->assertCreated()->assertJsonPath('data.status.value', 'authorized');
        $this->travel(21)->days();
        $this->artisan('competency:check-authorizations')->assertSuccessful();
        $this->getJson('/api/v1/competency/authorizations?status=expired')->assertJsonPath('data.meta.total', 1);
    }

    public function test_the_development_record_answers_readiness_and_downloads_as_a_pdf(): void
    {
        $ut = $this->competency('Ultrasonic Testing Level 2', 'technical');
        $reporting = $this->competency('Technical report writing');
        $this->require($this->officer, [$ut => 3, $reporting => 3]);
        $employee = $this->employeeIn($this->officer);
        $this->assess($employee, [$ut => 2, $reporting => 3]);

        $plan = TrainingPlan::create(['year' => 2026, 'title' => '2026 plan', 'approval_status' => 'approved']);
        $line = $plan->items()->create([
            'employee_id' => $employee->id, 'title' => 'UT Level 2', 'nature' => 'technical_development', 'category' => 'technician_technical',
            'quarter' => 'Q3', 'delivery' => 'external', 'cost' => 0, 'approval_status' => 'approved',
        ]);
        $this->postJson('/api/v1/competency/actions', [
            'employee_uuid' => $employee->uuid, 'competency_uuid' => $ut, 'method' => 'training', 'training_plan_item_uuid' => $line->uuid,
        ])->assertCreated();
        $this->putJson("/api/v1/training-plan/items/{$line->uuid}", ['status' => 'completed', 'hours' => 40, 'score' => 78])->assertOk();

        $this->getJson("/api/v1/competency/employees/{$employee->uuid}")->assertOk()
            ->assertJsonPath('data.readiness.meets_requirements', false)
            ->assertJsonPath('data.readiness.open_actions', 1)
            ->assertJsonPath('data.readiness.gaps_without_plan', 0)
            ->assertJsonPath('data.trainings.0.title', 'UT Level 2')
            ->assertJsonPath('data.trainings.0.hours', '40.0')
            ->assertJsonPath('data.trainings.0.competencies', ['Ultrasonic Testing Level 2'])
            ->assertJsonPath('data.trainings.0.feedback.status', 'due');

        $this->get("/api/v1/competency/employees/{$employee->uuid}/record")->assertOk()->assertHeader('content-type', 'application/pdf');

        Sanctum::actingAs(User::where('employee_id', $employee->id)->firstOrFail());
        $this->get('/api/v1/competency/mine/record')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/api/v1/competency/employees/{$employee->uuid}/record")->assertOk();

        Sanctum::actingAs($this->userWithRole('staff'));
        $this->getJson("/api/v1/competency/employees/{$employee->uuid}/record")->assertForbidden();
    }

    public function test_training_plan_preparers_read_the_library_but_not_the_matrix(): void
    {
        $this->competency('Ultrasonic Testing Level 2', 'technical');
        $officer = $this->userWithRole('staff');
        $officer->givePermissionTo('prepare-training-plan');
        Sanctum::actingAs($officer);

        $this->getJson('/api/v1/competency/competencies')->assertOk()->assertJsonPath('data.data.0.name', 'Ultrasonic Testing Level 2');
        $this->getJson('/api/v1/competency/options')->assertOk();
        $this->getJson('/api/v1/competency/matrix')->assertForbidden();
        $this->getJson('/api/v1/competency/gaps')->assertForbidden();

        Sanctum::actingAs($this->userWithRole('staff'));
        $this->getJson('/api/v1/competency/competencies')->assertForbidden();
    }

    public function test_every_development_method_in_the_procedure_can_be_chosen(): void
    {
        $methods = collect($this->getJson('/api/v1/competency/options')->assertOk()->json('data.methods'))->keyBy('value');

        foreach (['seminar', 'professional_membership', 'temporary_assignment', 'project', 'observation', 'knowledge_sharing'] as $method) {
            $this->assertTrue($methods->has($method), "{$method} is offered");
        }
        $this->assertSame('Maintenance of membership and application of professional knowledge', $methods['professional_membership']['effectiveness_check']);
        $this->assertSame('Demonstrated ability to perform assigned duties', $methods['temporary_assignment']['effectiveness_check']);
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
