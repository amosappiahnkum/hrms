<?php

namespace Tests\Feature;

use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Appraisal\AssessmentWindow;
use App\Models\Config\Department;
use App\Models\QuestionBank\Question;
use App\Models\QuestionBank\QuestionUsage;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/**
 * Appraisal templates with two sections: the employee answers theirs, the supervisor answers their own questions at
 * review. The overall score splits the two by the template's supervisor_weight (default 40% / 60%).
 */
class AppraisalSupervisorSectionTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $head;
    private User $officer;
    private User $hr;
    private Assessment $assessment;
    private AssessmentWindow $window;
    private Question $selfQuestion;
    private Question $supervisorQuestion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess(['appraisal.enabled']);
        Notification::fake();

        $this->head = $this->userWithRole('hod');
        $this->officer = $this->userWithRole('staff');
        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['finalize-appraisals', 'manage-appraisal-windows', 'manage-appraisal-templates']);
        $inspection = Department::create(['name' => 'Inspection', 'level' => 0, 'hod' => $this->head->employee_id]);
        $this->head->employee->update(['department_id' => $inspection->id]);
        $this->officer->employee->update(['department_id' => $inspection->id]);

        $this->assessment = Assessment::create(['title' => 'Annual appraisal', 'type' => 'appraisal', 'is_active' => true, 'user_id' => $this->hr->id]);
        $this->selfQuestion = Question::create(['type' => 'rating', 'text' => 'Quality of my work', 'user_id' => $this->hr->id]);
        $this->supervisorQuestion = Question::create(['type' => 'rating', 'text' => 'Leadership potential', 'user_id' => $this->hr->id]);

        // HR sets the template up: one question each, the supervisor's marked as theirs.
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/appraisal/templates/{$this->assessment->uuid}/questions/sync", ['questions' => [
            ['uuid' => $this->selfQuestion->uuid, 'order' => 0],
            ['uuid' => $this->supervisorQuestion->uuid, 'order' => 1, 'respondent' => 'supervisor'],
        ]])->assertOk()->assertJsonPath('data.questions.1.respondent', 'supervisor')->assertJsonPath('data.supervisor_weight', 60);

        $this->window = AssessmentWindow::create([
            'assessment_id' => $this->assessment->id, 'title' => '2026 appraisal', 'status' => 'open',
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString(), 'user_id' => $this->hr->id,
        ]);
        $this->window->snapshotQuestionsFromTemplate();
    }

    /** The officer answers their section (4) and submits. */
    private function submitted(): AssessmentAttempt
    {
        Sanctum::actingAs($this->officer);
        $uuid = $this->postJson("/api/v1/my/assessments/{$this->window->uuid}/start")->assertOk()->json('data.attempt.uuid');
        $this->postJson("/api/v1/my/assessments/attempts/{$uuid}/responses", ['responses' => [
            ['question_uuid' => $this->selfQuestion->uuid, 'answer' => 'Good', 'score' => 4],
        ]])->assertOk();
        $this->postJson("/api/v1/my/assessments/attempts/{$uuid}/submit")->assertOk();

        return AssessmentAttempt::where('uuid', $uuid)->first();
    }

    private function answerSupervisorSection(AssessmentAttempt $attempt, int $score = 5)
    {
        Sanctum::actingAs($this->head);

        return $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/assessment", ['responses' => [
            ['question_uuid' => $this->supervisorQuestion->uuid, 'answer' => 'Strong', 'score' => $score],
        ]]);
    }

    public function test_the_snapshot_keeps_who_answers_each_question(): void
    {
        $this->assertSame(
            ['employee', 'supervisor'],
            QuestionUsage::where('usable_type', AssessmentWindow::class)->where('usable_id', $this->window->id)->orderBy('order')->pluck('respondent')->all()
        );
    }

    public function test_the_employee_answers_only_their_section(): void
    {
        Sanctum::actingAs($this->officer);
        $start = $this->postJson("/api/v1/my/assessments/{$this->window->uuid}/start")->assertOk();
        $this->assertSame([$this->selfQuestion->uuid], array_column($start->json('data.questions'), 'uuid'));
        $uuid = $start->json('data.attempt.uuid');

        $this->postJson("/api/v1/my/assessments/attempts/{$uuid}/responses", ['responses' => [
            ['question_uuid' => $this->supervisorQuestion->uuid, 'answer' => 'Me!', 'score' => 5],
        ]])->assertUnprocessable();
        // Their section answered is enough to submit.
        $this->postJson("/api/v1/my/assessments/attempts/{$uuid}/responses", ['responses' => [
            ['question_uuid' => $this->selfQuestion->uuid, 'answer' => 'Good', 'score' => 4],
        ]])->assertOk();
        $this->postJson("/api/v1/my/assessments/attempts/{$uuid}/submit")->assertOk();

        // …and they can't answer the supervisor's section through its endpoint either.
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$uuid}/assessment", ['responses' => [
            ['question_uuid' => $this->supervisorQuestion->uuid, 'score' => 5],
        ]])->assertForbidden();
    }

    public function test_the_supervisor_answers_their_section_before_confirming(): void
    {
        $attempt = $this->submitted();
        Sanctum::actingAs($this->head);
        $pending = $this->getJson('/api/v1/appraisal/supervisor/pending')->assertOk()->json('data.0');
        $this->assertSame([$this->supervisorQuestion->uuid], array_column($pending['supervisor_questions'], 'uuid'));

        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/confirm")
            ->assertUnprocessable()->assertJsonPath('unanswered', 1);
        // Their own questions aren't "changes" to the employee's answers, and the employee's aren't theirs to answer.
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/responses/override", ['responses' => [
            ['question_uuid' => $this->supervisorQuestion->uuid, 'score' => 5],
        ]])->assertUnprocessable();
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/assessment", ['responses' => [
            ['question_uuid' => $this->selfQuestion->uuid, 'score' => 1],
        ]])->assertUnprocessable();

        $this->answerSupervisorSection($attempt, 3)->assertOk();
        $this->answerSupervisorSection($attempt, 5)->assertOk();
        $this->assertSame(1, $attempt->responses()->where('question_id', $this->supervisorQuestion->id)->count());
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/confirm")->assertOk();
    }

    public function test_the_overall_score_splits_the_sections_40_60_or_as_set(): void
    {
        app(SettingService::class)->set('appraisal.review_flow', 'direct');
        $attempt = $this->submitted();
        $this->answerSupervisorSection($attempt, 5)->assertOk();
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/confirm")->assertOk();

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/appraisal/hr/attempts/{$attempt->uuid}/complete")->assertOk();
        $attempt->refresh();
        // 40% × 4 + 60% × 5
        $this->assertSame(['4.60', '4.00', '4.00', '5.00'], [$attempt->score, $attempt->self_score, $attempt->employee_section_score, $attempt->supervisor_section_score]);

        // A template can split it differently.
        $this->assessment->update(['supervisor_weight' => 50]);
        $this->assertSame(4.5, $attempt->fresh()->sectionScores()['score']);
    }

    public function test_with_nobody_above_it_goes_to_hr_and_scores_the_self_assessment_only(): void
    {
        Sanctum::actingAs($this->head);
        $uuid = $this->postJson("/api/v1/my/assessments/{$this->window->uuid}/start")->assertOk()->json('data.attempt.uuid');
        $this->postJson("/api/v1/my/assessments/attempts/{$uuid}/responses", ['responses' => [
            ['question_uuid' => $this->selfQuestion->uuid, 'score' => 3],
        ]])->assertOk();
        $this->postJson("/api/v1/my/assessments/attempts/{$uuid}/submit")->assertOk();
        $this->assertSame(AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED, AssessmentAttempt::where('uuid', $uuid)->value('status'));

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/appraisal/hr/attempts/{$uuid}/complete")->assertOk();
        $attempt = AssessmentAttempt::where('uuid', $uuid)->first();
        $this->assertSame(['3.00', null], [$attempt->score, $attempt->supervisor_section_score]);
    }

    public function test_the_employee_sees_the_supervisors_section_only_when_acknowledging(): void
    {
        $attempt = $this->submitted();
        $this->answerSupervisorSection($attempt)->assertOk();

        // Not while the supervisor is still on it…
        Sanctum::actingAs($this->officer);
        $this->assertSame([], $this->getJson("/api/v1/my/assessments/{$this->window->uuid}/review")->json('data.supervisor_section'));

        // …then when it comes back to acknowledge.
        Sanctum::actingAs($this->head);
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/confirm")->assertOk();
        Sanctum::actingAs($this->officer);
        $this->assertSame(
            [['question_uuid' => $this->supervisorQuestion->uuid, 'question_text' => 'Leadership potential', 'answer' => 'Strong', 'score' => 5]],
            $this->getJson("/api/v1/my/assessments/{$this->window->uuid}/review")->json('data.supervisor_section')
        );
    }

    public function test_in_the_direct_flow_the_employee_never_sees_the_supervisors_section(): void
    {
        app(SettingService::class)->set('appraisal.review_flow', 'direct');
        $attempt = $this->submitted();
        $this->answerSupervisorSection($attempt)->assertOk();
        $this->postJson("/api/v1/appraisal/supervisor/attempts/{$attempt->uuid}/confirm")->assertOk();
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/appraisal/hr/attempts/{$attempt->uuid}/complete")->assertOk();

        Sanctum::actingAs($this->officer);
        $review = $this->getJson("/api/v1/my/assessments/{$this->window->uuid}/review")->assertOk()->json('data');
        $this->assertSame([], $review['supervisor_section']);
        $this->assertSame([null, null], [$review['supervisor_section_score'], $review['employee_section_score']]);
        $this->assertNotNull($review['score']);
        $this->get("/api/v1/appraisal/attempts/{$attempt->uuid}/pdf")->assertOk();

        // HR sees both sections.
        Sanctum::actingAs($this->hr);
        $responses = $this->getJson('/api/v1/appraisal/hr/pending')->json('data.0.responses');
        $this->assertEqualsCanonicalizing(['employee', 'supervisor'], array_column($responses, 'respondent'));
        $this->get("/api/v1/appraisal/hr/attempts/{$attempt->uuid}/pdf")->assertOk();
    }
}
