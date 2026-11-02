<?php

namespace Tests\Feature;

use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Appraisal\AssessmentWindow;
use App\Models\Appraisal\EmployeeKpi;
use App\Models\Appraisal\JobKpi;
use App\Models\Config\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/**
 * KPI plans: the supervisor (or HR) agrees an employee's targets for a period ahead of the appraisal; the employee
 * sees them all year, and the appraisal for that period starts from them.
 */
class KpiPlanTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $head;
    private User $officer;
    private User $colleague;
    private User $hr;
    private Assessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess(['appraisal.enabled']);
        Notification::fake();

        $this->head = $this->userWithRole('hod');
        $this->officer = $this->userWithRole('staff');
        $this->colleague = $this->userWithRole('staff');
        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['finalize-appraisals']);
        $inspection = Department::create(['name' => 'Inspection', 'level' => 0, 'hod' => $this->head->employee_id]);
        foreach ([$this->head, $this->officer, $this->colleague] as $user) {
            $user->employee->update(['department_id' => $inspection->id]);
        }
        $this->assessment = Assessment::create([
            'title' => 'Annual appraisal', 'type' => 'appraisal', 'is_active' => true, 'include_next_period_targets' => true, 'user_id' => $this->hr->id,
        ]);
    }

    private function plan(User $as, User $for, string $from = '2026-01-01', string $to = '2026-12-31', array $kpis = [])
    {
        Sanctum::actingAs($as);

        return $this->putJson("/api/v1/appraisal/kpi-plans/{$for->employee->uuid}", [
            'period_start' => $from, 'period_end' => $to,
            'kpis' => $kpis ?: [['description' => 'Inspections completed', 'target' => 120], ['description' => 'Reports on time', 'target' => 95]],
        ]);
    }

    private function window(string $from, string $to): AssessmentWindow
    {
        return AssessmentWindow::create([
            'assessment_id' => $this->assessment->id, 'title' => "Appraisal {$from}", 'status' => 'open',
            'period_start' => $from, 'period_end' => $to,
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString(), 'user_id' => $this->hr->id,
        ]);
    }

    public function test_the_supervisor_or_hr_sets_the_plan_and_the_employee_sees_it(): void
    {
        $this->plan($this->head, $this->officer)->assertOk()->assertJsonPath('data.0.kpis.1.target', 95);
        $this->plan($this->hr, $this->officer)->assertOk();
        $this->plan($this->colleague, $this->officer)->assertForbidden();
        $this->plan($this->head, $this->officer, kpis: [['description' => 'A', 'target' => 1], ['description' => ' a ', 'target' => 2]])
            ->assertUnprocessable();
        $this->plan($this->head, $this->officer, '2026-12-31', '2026-01-01')->assertJsonValidationErrors('period_end');

        // Replacing a period keeps one plan for it, and the descriptions go into the department's library.
        $this->assertSame(2, EmployeeKpi::where('employee_id', $this->officer->employee_id)->count());
        $this->assertSame(2, JobKpi::count());

        Sanctum::actingAs($this->officer);
        $this->getJson('/api/v1/my/kpis')->assertOk()
            ->assertJsonPath('data.0.period_start', '2026-01-01')
            ->assertJsonPath('data.0.kpis.0.description', 'Inspections completed');
        $this->getJson("/api/v1/appraisal/kpi-plans/{$this->officer->employee->uuid}")->assertForbidden();

        Sanctum::actingAs($this->head);
        $team = collect($this->getJson('/api/v1/appraisal/kpi-plans/team?date=2026-06-01')->assertOk()->json('data'));
        $this->assertCount(2, $team->firstWhere('employee.id', $this->officer->employee->uuid)['kpis']);
        $this->assertCount(0, $team->firstWhere('employee.id', $this->colleague->employee->uuid)['kpis']);
        $this->getJson("/api/v1/appraisal/kpi-plans/{$this->officer->employee->uuid}")->assertOk()->assertJsonCount(2, 'data.library');
    }

    public function test_the_appraisal_starts_from_the_plan_for_its_period(): void
    {
        $this->plan($this->head, $this->officer)->assertOk();
        $this->plan($this->head, $this->officer, '2025-01-01', '2025-12-31', [['description' => 'Old target', 'target' => 1]])->assertOk();

        Sanctum::actingAs($this->officer);
        $uuid = $this->postJson("/api/v1/my/assessments/{$this->window('2026-01-01', '2026-12-31')->uuid}/start")->assertOk()->json('data.attempt.uuid');
        $kpis = AssessmentAttempt::where('uuid', $uuid)->first()->kpis;
        $this->assertSame(['Inspections completed', 'Reports on time'], $kpis->pluck('description')->all());
        $this->assertSame([null, null], $kpis->pluck('actual')->all());
    }

    public function test_the_supervisor_can_still_add_kpis_during_the_appraisal(): void
    {
        // With a plan: the appraisal starts from it, and the supervisor adds to it at review.
        $this->plan($this->head, $this->officer)->assertOk();
        Sanctum::actingAs($this->officer);
        $uuid = $this->postJson("/api/v1/my/assessments/{$this->window('2026-01-01', '2026-12-31')->uuid}/start")->json('data.attempt.uuid');
        $this->postJson("/api/v1/my/assessments/attempts/{$uuid}/submit")->assertOk();

        Sanctum::actingAs($this->head);
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$uuid}/kpis/sync", ['kpis' => [
            ['description' => 'Inspections completed', 'target' => 120, 'actual' => 110],
            ['description' => 'Reports on time', 'target' => 95, 'actual' => 97],
            ['description' => 'Site audits led', 'target' => 4, 'actual' => 5],
        ]])->assertOk()->assertJsonCount(3, 'data');
        // The agreed plan itself is unchanged.
        $this->assertSame(2, EmployeeKpi::where('employee_id', $this->officer->employee_id)->count());

        // Without a plan: the supervisor adds them all at review, as before.
        Sanctum::actingAs($this->colleague);
        $uuid = $this->postJson("/api/v1/my/assessments/{$this->window('2026-01-01', '2026-12-31')->uuid}/start")->json('data.attempt.uuid');
        $this->assertCount(0, AssessmentAttempt::where('uuid', $uuid)->first()->kpis);
        $this->postJson("/api/v1/my/assessments/attempts/{$uuid}/submit")->assertOk();

        Sanctum::actingAs($this->head);
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$uuid}/kpis/sync", ['kpis' => [
            ['description' => 'Permits processed', 'target' => 300, 'actual' => 280],
        ]])->assertOk()->assertJsonPath('data.0.actual', 280);
    }

    public function test_next_period_targets_set_in_the_appraisal_become_the_next_plan(): void
    {
        $window = $this->window('2026-01-01', '2026-12-31');
        Sanctum::actingAs($this->officer);
        $uuid = $this->postJson("/api/v1/my/assessments/{$window->uuid}/start")->json('data.attempt.uuid');
        $this->postJson("/api/v1/my/assessments/attempts/{$uuid}/submit")->assertOk();

        Sanctum::actingAs($this->head);
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$uuid}/kpis/sync", [
            'period' => 'next', 'kpis' => [['description' => 'Train two juniors', 'target' => 2]],
        ])->assertOk()->assertJsonPath('data.0.period_start', '2027-01-01')->assertJsonPath('data.0.period_end', '2027-12-31');
        $this->assertSame('Train two juniors', $this->getJson('/api/v1/appraisal/supervisor/pending')->json('data.0.next_kpis.0.description'));

        // Edited later on the plan screen: still the appraisal's next targets.
        $this->plan($this->head, $this->officer, '2027-01-01', '2027-12-31', [['description' => 'Train three juniors', 'target' => 3]])->assertOk();
        $this->assertSame(['Train three juniors'], AssessmentAttempt::where('uuid', $uuid)->first()->nextKpis->pluck('description')->all());

        // The next appraisal starts from them.
        Sanctum::actingAs($this->officer);
        $next = $this->postJson("/api/v1/my/assessments/{$this->window('2027-01-01', '2027-12-31')->uuid}/start")->json('data.attempt.uuid');
        $this->assertSame(['Train three juniors'], AssessmentAttempt::where('uuid', $next)->first()->kpis->pluck('description')->all());
    }
}
