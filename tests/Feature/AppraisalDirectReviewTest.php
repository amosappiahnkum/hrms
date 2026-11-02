<?php

namespace Tests\Feature;

use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Appraisal\AssessmentWindow;
use App\Models\Config\Department;
use App\Models\QuestionBank\Question;
use App\Models\QuestionResponse;
use App\Models\User;
use App\Notifications\Appraisal\AppraisalNotification;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/**
 * The direct review flow (setting `appraisal.review_flow` = direct): employee → appraiser → HR. The employee doesn't
 * acknowledge, and doesn't see the appraiser's changes or comment; HR sees every change. Engineering › Inspection unit.
 */
class AppraisalDirectReviewTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $engHead;
    private User $unitHead;
    private User $officer;
    private User $hr;
    private AssessmentWindow $window;
    private Question $question;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess(['appraisal.enabled']);
        Notification::fake();

        $this->engHead = $this->userWithRole('hod');
        $this->unitHead = $this->userWithRole('hod');
        $this->officer = $this->userWithRole('staff');
        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['finalize-appraisals', 'manage-appraisal-windows']);
        $engineering = Department::create(['name' => 'Engineering', 'level' => 0, 'hod' => $this->engHead->employee_id]);
        $inspection = Department::create(['name' => 'Inspection', 'level' => 1, 'hod' => $this->unitHead->employee_id, 'parent_department_id' => $engineering->id]);
        $this->engHead->employee->update(['department_id' => $engineering->id]);
        $this->unitHead->employee->update(['department_id' => $inspection->id]);
        $this->officer->employee->update(['department_id' => $inspection->id]);
        app(SettingService::class)->set('appraisal.review_flow', 'direct');

        $assessment = Assessment::create(['title' => 'Annual appraisal', 'type' => 'appraisal', 'is_active' => true, 'user_id' => $this->hr->id]);
        $this->window = AssessmentWindow::create([
            'assessment_id' => $assessment->id, 'title' => '2026 appraisal', 'status' => 'open',
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString(), 'user_id' => $this->hr->id,
        ]);
        $this->question = Question::create(['type' => 'rating', 'text' => 'Quality of work', 'user_id' => $this->hr->id]);
        \App\Models\QuestionBank\QuestionUsage::create([
            'question_id' => $this->question->id, 'usable_type' => AssessmentWindow::class, 'usable_id' => $this->window->id,
            'order' => 0, 'user_id' => $this->hr->id,
        ]);
    }

    /** The officer's appraisal, answered (4) and submitted. */
    private function submitted(): AssessmentAttempt
    {
        $attempt = AssessmentAttempt::create([
            'assessment_window_id' => $this->window->id, 'assessment_id' => $this->window->assessment_id, 'user_id' => $this->officer->id,
            'status' => AssessmentAttempt::STATUS_DRAFT, 'started_at' => now(),
        ]);
        QuestionResponse::create([
            'question_id' => $this->question->id, 'user_id' => $this->officer->id, 'answer' => 'Good',
            'respondable_type' => AssessmentAttempt::class, 'respondable_id' => $attempt->id, 'score' => 4,
        ]);
        Sanctum::actingAs($this->officer);
        $this->postJson("/api/v1/my/assessments/attempts/{$attempt->uuid}/submit")->assertOk();

        return $attempt->fresh();
    }

    private function subject(AppraisalNotification $notification): string
    {
        return $notification->toMail($this->officer)->subject;
    }

    private function override(AssessmentAttempt $attempt, string $answer, int $score, string $why)
    {
        Sanctum::actingAs($this->unitHead);

        return $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/responses/override", ['responses' => [
            ['question_uuid' => $this->question->uuid, 'answer' => $answer, 'score' => $score, 'justification' => $why],
        ]]);
    }

    public function test_the_appraiser_changes_and_confirms_and_it_goes_straight_to_hr(): void
    {
        $attempt = $this->submitted();
        $this->assertSame('direct', $attempt->review_flow);

        // Changes stay with the appraiser; the employee isn't asked to acknowledge them.
        $this->override($attempt, 'Fair', 3, 'Missed two deadlines')->assertOk();
        $this->override($attempt, 'Average', 2, 'And a third')->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_PENDING_SUPERVISOR, $attempt->fresh()->status);
        Notification::assertNotSentTo($this->officer, AppraisalNotification::class);

        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/confirm", ['supervisor_comment' => 'Needs to plan better'])
            ->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED, $attempt->fresh()->status);
        Notification::assertSentTo($this->hr, AppraisalNotification::class, fn ($n) => $this->subject($n) === 'Appraisal Ready for Finalization: ' . $this->officer->name);
        // Told only that it moved on.
        Notification::assertSentTo($this->officer, AppraisalNotification::class, fn ($n) => $this->subject($n) === 'Your Appraisal Has Been Reviewed');

        // Nothing for the employee to acknowledge.
        Sanctum::actingAs($this->officer);
        $this->postJson("/api/v1/my/assessments/attempts/{$attempt->uuid}/acknowledge")->assertUnprocessable();

        // HR sees every change, in order: the employee's answer → first change → second change.
        Sanctum::actingAs($this->hr);
        $response = $this->getJson('/api/v1/appraisal/hr/pending')->assertOk()->json('data.0.responses.0');
        $this->assertSame(['Good', 4], [$response['answer'], $response['score']]);
        $this->assertSame(['Average', 2], [$response['supervisor_answer'], $response['supervisor_score']]);
        $this->assertSame(
            [['Good', 4, 'Fair', 3, 'Missed two deadlines'], ['Fair', 3, 'Average', 2, 'And a third']],
            array_map(fn ($r) => [$r['previous_answer'], $r['previous_score'], $r['answer'], $r['score'], $r['justification']], $response['revisions'])
        );
        $this->assertSame($this->unitHead->name, $response['revisions'][0]['revised_by']['name']);
    }

    public function test_the_employee_doesnt_see_the_appraisers_changes_or_comment(): void
    {
        $attempt = $this->submitted();
        $this->override($attempt, 'Fair', 3, 'Missed two deadlines')->assertOk();
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/confirm", ['supervisor_comment' => 'Needs to plan better'])->assertOk();
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/appraisal/hr/attempts/{$attempt->uuid}/complete")->assertOk();

        Sanctum::actingAs($this->officer);
        $review = $this->getJson("/api/v1/my/assessments/{$this->window->uuid}/review")->assertOk()->json('data');
        $this->assertSame(['Good', 4, null, null, null], [
            $review['responses'][0]['answer'], $review['responses'][0]['score'], $review['responses'][0]['supervisor_answer'],
            $review['responses'][0]['supervisor_score'], $review['responses'][0]['supervisor_justification'],
        ]);
        $this->assertNotNull($review['score']);
        $this->assertNull($review['self_score']);
        $this->assertNull($review['supervisor_comment']);
        $this->assertNotContains('answers_edited', array_column($review['events'], 'event_type'));
        $this->assertSame([null], array_values(array_unique(array_column(
            array_filter($review['events'], fn ($e) => $e['event_type'] === 'supervisor_confirmed'), 'comment'
        ))));

        // Nor through the sessions list.
        $mine = $this->getJson('/api/v1/my/assessments')->assertOk()->json('data.0.my_attempt');
        $this->assertNull($mine['supervisor_comment']);
        $this->assertNull($mine['self_score']);

        // Both PDFs render: the employee's copy and HR's (with the changes).
        $this->get("/api/v1/appraisal/attempts/{$attempt->uuid}/pdf")->assertOk();
        Sanctum::actingAs($this->hr);
        $this->get("/api/v1/appraisal/hr/attempts/{$attempt->uuid}/pdf")->assertOk();

        // The appraiser still sees their changes.
        Sanctum::actingAs($this->unitHead);
        $this->assertSame('Fair', $this->getJson('/api/v1/appraisal/supervisor/pending')->json('data.0.responses.0.supervisor_answer'));
    }

    public function test_the_appraiser_can_still_return_it_with_a_reason(): void
    {
        $attempt = $this->submitted();
        Sanctum::actingAs($this->unitHead);
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/return", ['supervisor_comment' => 'Add your KPIs'])->assertOk();

        Sanctum::actingAs($this->officer);
        $review = $this->getJson("/api/v1/my/assessments/{$this->window->uuid}/review")->json('data');
        $this->assertSame(AssessmentAttempt::STATUS_RETURNED, $review['status']);
        $this->assertSame('Add your KPIs', $review['supervisor_comment']);
        $this->postJson("/api/v1/my/assessments/attempts/{$attempt->uuid}/submit")->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_PENDING_SUPERVISOR, $attempt->fresh()->status);
    }

    public function test_endorsement_still_follows_the_appraisers_confirmation(): void
    {
        app(SettingService::class)->set('appraisal.endorsement_chain', ['stop' => 'top']);
        $attempt = $this->submitted();
        Sanctum::actingAs($this->unitHead);
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/confirm")->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_PENDING_ENDORSEMENT, $attempt->fresh()->status);

        Sanctum::actingAs($this->engHead);
        $this->postJson("/api/v1/appraisal/endorsements/{$attempt->uuid}/endorse")->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED, $attempt->fresh()->status);
    }

    public function test_the_flow_is_fixed_when_submitted(): void
    {
        $attempt = $this->submitted();
        app(SettingService::class)->set('appraisal.review_flow', 'acknowledge');

        $this->override($attempt, 'Fair', 3, 'Missed two deadlines')->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_PENDING_SUPERVISOR, $attempt->fresh()->status);
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/confirm")->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED, $attempt->fresh()->status);
    }

    public function test_the_acknowledge_flow_is_unchanged_and_keeps_the_changes_too(): void
    {
        app(SettingService::class)->set('appraisal.review_flow', 'acknowledge');
        $attempt = $this->submitted();
        $this->assertSame('acknowledge', $attempt->review_flow);

        $this->override($attempt, 'Fair', 3, 'Missed two deadlines')->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_PENDING_EMPLOYEE_ACKNOWLEDGMENT, $attempt->fresh()->status);
        $this->assertSame(1, \App\Models\QuestionResponseRevision::where('assessment_attempt_id', $attempt->id)->count());

        Sanctum::actingAs($this->officer);
        $this->assertSame('Fair', $this->getJson("/api/v1/my/assessments/{$this->window->uuid}/review")->json('data.responses.0.supervisor_answer'));
    }

    public function test_hr_sets_the_review_flow(): void
    {
        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/appraisal/settings', ['review_flow' => 'sideways'])->assertJsonValidationErrors('review_flow');
        $this->putJson('/api/v1/appraisal/settings', ['review_flow' => 'acknowledge'])->assertOk()
            ->assertJsonPath('data.review_flow', 'acknowledge')
            ->assertJsonPath('data.endorsement_chain.stop', 'none');
        $this->getJson('/api/v1/appraisal/settings')->assertJsonPath('data.review_flow', 'acknowledge');

        Sanctum::actingAs($this->officer);
        $this->putJson('/api/v1/appraisal/settings', ['review_flow' => 'direct'])->assertForbidden();
    }
}
