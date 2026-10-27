<?php

namespace Tests\Feature;

use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Appraisal\AssessmentWindow;
use App\Models\Config\Department;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/**
 * Appraisals: the supervisor appraises; once the employee acknowledges, the heads above endorse in turn before HR.
 * Technical Directorate › Engineering › Inspection unit.
 */
class AppraisalEndorsementTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $director;
    private User $engHead;
    private User $unitHead;
    private User $officer;
    private User $hr;
    private AssessmentWindow $window;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess(['appraisal.enabled']);
        Notification::fake();

        $this->director = $this->userWithRole('hod');
        $this->engHead = $this->userWithRole('hod');
        $this->unitHead = $this->userWithRole('hod');
        $this->officer = $this->userWithRole('staff');
        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['finalize-appraisals', 'manage-appraisal-windows']);
        $directorate = Department::create(['name' => 'Technical Directorate', 'level' => 0, 'hod' => $this->director->employee_id]);
        $engineering = Department::create(['name' => 'Engineering', 'level' => 1, 'hod' => $this->engHead->employee_id, 'parent_department_id' => $directorate->id]);
        $inspection = Department::create(['name' => 'Inspection', 'level' => 2, 'hod' => $this->unitHead->employee_id, 'parent_department_id' => $engineering->id]);
        $this->director->employee->update(['department_id' => $directorate->id]);
        $this->engHead->employee->update(['department_id' => $engineering->id]);
        $this->unitHead->employee->update(['department_id' => $inspection->id]);
        $this->officer->employee->update(['department_id' => $inspection->id]);
        app(SettingService::class)->set('org.department_levels', ['Directorate', 'Department', 'Unit']);

        $assessment = Assessment::create(['title' => 'Annual appraisal', 'type' => 'appraisal', 'is_active' => true, 'user_id' => $this->hr->id]);
        $this->window = AssessmentWindow::create([
            'assessment_id' => $assessment->id, 'title' => '2026 appraisal', 'status' => 'open',
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString(), 'user_id' => $this->hr->id,
        ]);
    }

    private function attempt(User $user, string $status = AssessmentAttempt::STATUS_DRAFT, ?User $appraiser = null): AssessmentAttempt
    {
        return AssessmentAttempt::create([
            'assessment_window_id' => $this->window->id, 'assessment_id' => $this->window->assessment_id, 'user_id' => $user->id,
            'status' => $status, 'supervisor_id' => $appraiser?->id, 'started_at' => now(),
        ]);
    }

    private function acknowledge(AssessmentAttempt $attempt)
    {
        Sanctum::actingAs($this->officer);

        return $this->postJson("/api/v1/my/assessments/attempts/{$attempt->uuid}/acknowledge");
    }

    public function test_the_supervisor_appraises_and_the_top_goes_straight_to_hr(): void
    {
        // The unit head by default…
        Sanctum::actingAs($this->officer);
        $this->postJson("/api/v1/my/assessments/attempts/{$this->attempt($this->officer)->uuid}/submit")->assertOk();
        Sanctum::actingAs($this->unitHead);
        $this->assertCount(1, $this->getJson('/api/v1/appraisal/supervisor/pending')->assertOk()->json('data'));

        // …or who they report to.
        $lead = $this->userWithRole('staff');
        $this->officer->employee->update(['reports_to_id' => $lead->employee_id]);
        Sanctum::actingAs($lead);
        $this->assertCount(1, $this->getJson('/api/v1/appraisal/supervisor/pending')->json('data'));
        Sanctum::actingAs($this->unitHead);
        $this->assertCount(0, $this->getJson('/api/v1/appraisal/supervisor/pending')->json('data'));

        // Nobody above the director: auto-confirmed for HR.
        Sanctum::actingAs($this->director);
        $top = $this->attempt($this->director);
        $this->postJson("/api/v1/my/assessments/attempts/{$top->uuid}/submit")->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED, $top->fresh()->status);
    }

    public function test_without_endorsement_acknowledging_sends_it_to_hr(): void
    {
        $attempt = $this->attempt($this->officer, AssessmentAttempt::STATUS_PENDING_EMPLOYEE_ACKNOWLEDGMENT, $this->unitHead);
        $this->acknowledge($attempt)->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED, $attempt->fresh()->status);
    }

    public function test_heads_above_endorse_in_turn_or_return_it_to_the_appraiser(): void
    {
        app(SettingService::class)->set('appraisal.endorsement_chain', ['stop' => 'top']);
        $attempt = $this->attempt($this->officer, AssessmentAttempt::STATUS_PENDING_EMPLOYEE_ACKNOWLEDGMENT, $this->unitHead);

        $this->acknowledge($attempt)->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_PENDING_ENDORSEMENT, $attempt->fresh()->status);
        $this->assertSame(['Head of Engineering', 'Head of Technical Directorate'], array_column($attempt->fresh()->approval->steps, 'name'));

        // HR doesn't see it yet; it's the engineering head's turn, not the director's.
        Sanctum::actingAs($this->hr);
        $this->assertCount(0, $this->getJson('/api/v1/appraisal/hr/pending')->json('data'));
        Sanctum::actingAs($this->director);
        $this->assertCount(0, $this->getJson('/api/v1/appraisal/endorsements')->json('data'));
        $this->postJson("/api/v1/appraisal/endorsements/{$attempt->uuid}/endorse")->assertForbidden();

        Sanctum::actingAs($this->engHead);
        $this->getJson('/api/v1/appraisal/endorsements')->assertOk()->assertJsonPath('data.0.uuid', $attempt->uuid);
        $this->postJson("/api/v1/appraisal/endorsements/{$attempt->uuid}/endorse", ['comment' => 'Fair'])->assertOk();

        // The director returns it: back to the appraiser, with the reason.
        Sanctum::actingAs($this->director);
        $this->postJson("/api/v1/appraisal/endorsements/{$attempt->uuid}/return")->assertJsonValidationErrors('comment');
        $this->postJson("/api/v1/appraisal/endorsements/{$attempt->uuid}/return", ['comment' => 'Recheck the KPI scores'])->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_PENDING_SUPERVISOR, $attempt->fresh()->status);
        $this->assertSame(['sent_for_endorsement', 'endorsed', 'endorsement_returned'], $attempt->events()->orderBy('id')->pluck('event_type')->intersect(['sent_for_endorsement', 'endorsed', 'endorsement_returned'])->values()->all());

        // The appraiser confirms again, the employee acknowledges, and endorsement starts over: both endorse, then HR.
        Sanctum::actingAs($this->unitHead);
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/confirm")->assertOk();
        $this->acknowledge($attempt->fresh())->assertOk();
        foreach ([$this->engHead, $this->director] as $head) {
            Sanctum::actingAs($head);
            $this->postJson("/api/v1/appraisal/endorsements/{$attempt->uuid}/endorse")->assertOk();
        }
        $this->assertSame(AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED, $attempt->fresh()->status);
        Sanctum::actingAs($this->hr);
        $this->assertSame($attempt->uuid, $this->getJson('/api/v1/appraisal/hr/pending')->json('data.0.uuid'));

        // Endorsements stay out of the payroll approvals inbox.
        Sanctum::actingAs($this->engHead);
        $this->assertSame(0, \App\Models\Payroll\Approval::where('process', '!=', 'appraisal')->count());
    }

    public function test_endorsement_stops_at_a_level_or_a_number(): void
    {
        $attempt = $this->attempt($this->officer, AssessmentAttempt::STATUS_PENDING_EMPLOYEE_ACKNOWLEDGMENT, $this->unitHead)->load('user.employee', 'supervisor');
        $steps = function (array $rule) use ($attempt) {
            app(SettingService::class)->set('appraisal.endorsement_chain', $rule);

            return array_column(app(\App\Services\Appraisal\AppraisalEndorsement::class)->steps($attempt), 'name');
        };
        $this->assertSame([], $steps(['stop' => 'none']));
        $this->assertSame(['Head of Engineering'], $steps(['stop' => 'count', 'count' => 1]));
        $this->assertSame(['Head of Engineering'], $steps(['stop' => 'level', 'level' => 1]));
        $this->assertSame(['Head of Engineering', 'Head of Technical Directorate'], $steps(['stop' => 'level', 'level' => 0]));

        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/appraisal/settings', ['endorsement_chain' => ['stop' => 'count', 'count' => 0]])->assertJsonValidationErrors('endorsement_chain.count');
        $this->putJson('/api/v1/appraisal/settings', ['endorsement_chain' => ['stop' => 'level', 'level' => 0]])->assertOk()
            ->assertJsonPath('data.endorsement_chain.stop', 'level');
        Sanctum::actingAs($this->officer);
        $this->putJson('/api/v1/appraisal/settings', ['endorsement_chain' => ['stop' => 'top']])->assertForbidden();
    }
}
