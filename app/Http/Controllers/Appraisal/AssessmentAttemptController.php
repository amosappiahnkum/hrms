<?php

namespace App\Http\Controllers\Appraisal;

use App\Exports\AppraisalExport;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppraisalKpiResource;
use App\Http\Resources\AppraisalTrainingResource;
use App\Http\Resources\AssessmentAttemptResource;
use App\Http\Resources\AssessmentWindowResource;
use App\Http\Resources\EmployeeKpiResource;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Appraisal\AssessmentAttemptEvent;
use App\Models\Appraisal\AssessmentWindow;
use App\Models\Appraisal\AppraisalKpi;
use App\Models\Appraisal\AppraisalTraining;
use App\Models\Appraisal\EmployeeKpi;
use App\Models\Appraisal\JobKpi;
use App\Models\QuestionBank\Question;
use App\Models\QuestionBank\QuestionUsage;
use App\Models\QuestionResponse;
use App\Models\QuestionResponseRevision;
use App\Models\User;
use App\Notifications\Appraisal\AppraisalNotification;
use App\Services\Appraisal\AppraisalEndorsement;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AssessmentAttemptController extends Controller
{
    /**
     * Open sessions the authenticated user can take.
     * Scoped by the user's job_category from their job_detail.
     */
    public function myWindows(): AnonymousResourceCollection
    {
        $userId = auth()->id();

        $jobCategoryId = DB::table('job_details')
            ->join('employees', 'job_details.employee_id', '=', 'employees.id')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->where('users.id', $userId)
            ->value('job_details.job_category_id');

        $windows = AssessmentWindow::where('status', 'open')
            ->whereHas('assessment', function ($q) use ($jobCategoryId) {
                $q->where(function ($inner) use ($jobCategoryId) {
                    // "All employees" — no categories assigned
                    $inner->whereDoesntHave('jobCategories')
                        ->orWhereHas('jobCategories', function ($cat) use ($jobCategoryId) {
                            $cat->where('job_categories.id', $jobCategoryId);
                        });
                });
            })
            ->with(['assessment.jobCategories', 'myAttempt'])
            ->paginate(15);

        return AssessmentWindowResource::collection($windows);
    }

    /**
     * Start or resume an attempt. Loads the window's snapshotted questions.
     */
    public function startOrResume(AssessmentWindow $assessmentWindow): JsonResponse
    {
        if (!$assessmentWindow->isOpen()) {
            return response()->json(['message' => 'This assessment session is not currently open.'], 403);
        }

        $attempt = AssessmentAttempt::firstOrCreate(
            ['assessment_window_id' => $assessmentWindow->id, 'user_id' => auth()->id()],
            ['status' => AssessmentAttempt::STATUS_DRAFT, 'started_at' => now()]
        );

        if (!$attempt->isEditable()) {
            return response()->json([
                'message' => 'This attempt has been submitted and can no longer be edited.',
                'status'  => $attempt->status,
            ], 422);
        }

        // Load questions from the window snapshot (not the template — they may have diverged)
        $assessmentWindow->load(['assessment.jobCategories', 'questionUsages.question.options', 'questionUsages.question.dependsOn', 'questionUsages.question.questionCategory']);

        $includeTrainings = (bool) $assessmentWindow->assessment->include_training_section;
        if ($attempt->wasRecentlyCreated) {
            $attempt->setRelation('window', $assessmentWindow);

            if ($includeTrainings) {
                $attempt->prefillTrainings();
            }
            if ($assessmentWindow->assessment->type === 'appraisal') {
                $attempt->startKpisFromPlan();
            }
        }

        $attempt->load(['responses.question', 'trainings']);
        $attempt->setRelation('window', $assessmentWindow);
        $employeeUsages = $attempt->questionUsagesFor(QuestionUsage::RESPONDENT_EMPLOYEE);

        return ApiResponse::success([
            'attempt'         => AssessmentAttemptResource::make($attempt),
            'window'          => AssessmentWindowResource::make($assessmentWindow),
            'requires_approval' => $assessmentWindow->assessment->type === 'appraisal',
            'include_training_section' => $includeTrainings,
            'trainings'       => AppraisalTrainingResource::collection($attempt->trainings),
            // The employee's section only: the supervisor answers theirs at review.
            'questions'       => $employeeUsages->map(fn ($usage) => $usage->toQuestionPayload())->sortBy('order')->values(),
            'saved_responses' => $attempt->responses
                ->whereIn('question_id', $employeeUsages->pluck('question_id'))
                ->map(fn ($r) => [
                    'question_uuid' => $r->question->uuid ?? null,
                    'answer'        => $r->answer,
                    'score'         => $r->score,
                ])->values(),
        ]);
    }

    /**
     * Read-only view of the authenticated user's submitted responses for a window.
     */
    public function myAttemptReview(AssessmentWindow $assessmentWindow): JsonResponse
    {
        $attempt = AssessmentAttempt::where('assessment_window_id', $assessmentWindow->id)
            ->where('user_id', auth()->id())
            ->with(['responses.question', 'events.actor', 'kpis', 'nextKpis', 'trainings'])
            ->firstOrFail();

        $assessmentWindow->load(['assessment', 'questionUsages.question']);

        $attempt->setRelation('window', $assessmentWindow);

        // Build an ordered map of question_uuid → question_text using the window snapshot order (the employee's section)
        $orderedQuestions = $attempt->questionUsagesFor(QuestionUsage::RESPONDENT_EMPLOYEE)
            ->sortBy('order')
            ->map(fn ($u) => [
                'uuid'  => $u->question->uuid,
                'text'  => $u->question->text,
                'order' => $u->order,
            ])
            ->values();

        // Index responses by question UUID for fast lookup
        $responsesByUuid = $attempt->responses->keyBy(fn ($r) => $r->question?->uuid);

        // Direct review flow: the employee sees their own answers only, not the appraiser's changes.
        $hide = $attempt->hidesReviewFrom(auth()->id());

        $responses = $orderedQuestions->map(fn ($q) => [
            'question_uuid'            => $q['uuid'],
            'question_text'            => $q['text'],
            'answer'                   => $responsesByUuid[$q['uuid']]?->answer,
            'score'                    => $responsesByUuid[$q['uuid']]?->score,
            'supervisor_answer'        => $hide ? null : $responsesByUuid[$q['uuid']]?->supervisor_answer,
            'supervisor_score'         => $hide ? null : $responsesByUuid[$q['uuid']]?->supervisor_score,
            'supervisor_justification' => $hide ? null : $responsesByUuid[$q['uuid']]?->supervisor_justification,
            'supervisor_updated_at'    => $hide ? null : $responsesByUuid[$q['uuid']]?->supervisor_updated_at?->toDateTimeString(),
        ]);

        // The supervisor's own section: acknowledge flow, once they've finished it.
        $showSupervisorSection = $attempt->showsSupervisorSectionTo(auth()->id());
        $supervisorSection = $showSupervisorSection
            ? $attempt->questionUsagesFor(QuestionUsage::RESPONDENT_SUPERVISOR)->sortBy('order')->values()->map(fn ($u) => [
                'question_uuid' => $u->question->uuid,
                'question_text' => $u->question->text,
                'answer'        => $responsesByUuid[$u->question->uuid]?->answer,
                'score'         => $responsesByUuid[$u->question->uuid]?->score,
            ])
            : [];

        return ApiResponse::success([
            'status'               => $attempt->status,
            'attempt_uuid'         => $attempt->uuid,
            'review_flow'          => $attempt->review_flow ?? AssessmentAttempt::REVIEW_FLOW_ACKNOWLEDGE,
            'score'                => $attempt->score,
            'self_score'           => $hide ? null : $attempt->self_score,
            'employee_section_score'   => $hide ? null : $attempt->employee_section_score,
            'supervisor_section_score' => $showSupervisorSection ? $attempt->supervisor_section_score : null,
            'kpi_score'            => $attempt->kpi_score,
            'supervisor_comment'   => $attempt->supervisorCommentFor(auth()->id()),
            'finalized_at'         => $attempt->finalized_at?->toDateTimeString(),
            'employee_signed_at'   => $attempt->employee_signed_at?->toDateTimeString(),
            'supervisor_signed_at' => $attempt->supervisor_signed_at?->toDateTimeString(),
            'responses'            => $responses,
            'supervisor_section'   => $supervisorSection,
            'kpis'         => AppraisalKpiResource::collection($attempt->kpis),
            'next_kpis'    => EmployeeKpiResource::collection($attempt->nextKpis),
            'include_next_period_targets' => (bool) $assessmentWindow->assessment?->include_next_period_targets,
            'include_training_section' => (bool) $assessmentWindow->assessment?->include_training_section,
            'trainings'    => AppraisalTrainingResource::collection($attempt->trainings),
            'events'       => $attempt->eventsVisibleTo(auth()->id())->map(fn ($e) => [
                'uuid'       => $e->uuid,
                'event_type' => $e->event_type,
                'comment'    => $e->comment,
                'actor'      => $e->actor_id ? ['uuid' => $e->actor?->uuid, 'name' => $e->actor?->name] : null,
                'created_at' => $e->created_at?->toDateTimeString(),
            ]),
        ]);
    }

    /**
     * Employee replaces the Training & Development list while the attempt is editable.
     * Rows sent with a uuid keep their source link (internal course / certification);
     * new rows are recorded as manual entries.
     */
    public function syncTrainings(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeAttempt($attempt);

        if (!$attempt->isEditable()) {
            return response()->json(['message' => 'This attempt can no longer be edited.'], 422);
        }

        $request->validate([
            'trainings'              => ['present', 'array', 'max:50'],
            'trainings.*.uuid'       => ['nullable', 'string'],
            'trainings.*.subject'    => ['required', 'string', 'max:255'],
            'trainings.*.type'       => ['required', 'in:' . implode(',', AppraisalTraining::TYPES)],
            'trainings.*.start_date' => ['nullable', 'date'],
            'trainings.*.end_date'   => ['nullable', 'date', 'after_or_equal:trainings.*.start_date'],
        ]);

        DB::transaction(function () use ($request, $attempt) {
            $existing = $attempt->trainings()->get()->keyBy('uuid');
            $attempt->trainings()->delete();

            foreach ($request->trainings as $index => $item) {
                $previous = isset($item['uuid']) ? $existing->get($item['uuid']) : null;

                AppraisalTraining::create([
                    'uuid'                      => $previous?->uuid,
                    'assessment_attempt_id'     => $attempt->id,
                    'subject'                   => $item['subject'],
                    'type'                      => $item['type'],
                    'start_date'                => $item['start_date'] ?? null,
                    'end_date'                  => $item['end_date'] ?? null,
                    'source'                    => $previous?->source ?? AppraisalTraining::SOURCE_MANUAL,
                    'course_enrollment_id'      => $previous?->course_enrollment_id,
                    'employee_certification_id' => $previous?->employee_certification_id,
                    'order'                     => $index,
                ]);
            }
        });

        return ApiResponse::success(
            AppraisalTrainingResource::collection($attempt->trainings()->get()),
            'Training records saved.'
        );
    }

    /**
     * Save responses (upsert). Works for both auto-save and manual save draft.
     */
    public function saveResponses(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeAttempt($attempt);

        if (!$attempt->isEditable()) {
            return response()->json(['message' => 'This attempt can no longer be edited.'], 422);
        }

        $request->validate([
            'responses'                 => ['required', 'array'],
            'responses.*.question_uuid' => ['required', 'exists:questions,uuid'],
            'responses.*.answer'        => ['nullable', 'string'],
            'responses.*.score'         => ['nullable', 'integer'],
        ]);

        // Appraisals: only the employee's section (the supervisor answers theirs at review).
        if ($attempt->window && $this->notIn($request->responses, $attempt, QuestionUsage::RESPONDENT_EMPLOYEE)) {
            return response()->json(['message' => 'Some of these questions are not yours to answer.'], 422);
        }

        DB::transaction(function () use ($request, $attempt) {
            foreach ($request->responses as $item) {
                $question = Question::where('uuid', $item['question_uuid'])->firstOrFail();
                QuestionResponse::updateOrCreate(
                    [
                        'question_id'      => $question->id,
                        'user_id'          => auth()->id(),
                        'respondable_type' => AssessmentAttempt::class,
                        'respondable_id'   => $attempt->id,
                    ],
                    [
                        'answer' => $item['answer'] ?? null,
                        'score'  => $item['score'] ?? null,
                    ]
                );
            }
        });

        return ApiResponse::success(['saved' => count($request->responses)]);
    }

    /**
     * Employee submits their attempt.
     * - Non-appraisal: directly 'submitted' (done).
     * - Appraisal: moves to 'pending_supervisor', requires employee comment.
     */
    public function submit(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeAttempt($attempt);

        if (!$attempt->isEditable()) {
            return response()->json(['message' => 'Already submitted.'], 422);
        }

        $this->validateRequiredQuestionsAnswered($attempt);

        // Training quiz: no supervisor stage, just submit and update progress
        if ($attempt->isTrainingQuiz()) {
            DB::transaction(function () use ($attempt) {
                $attempt->update([
                    'status'       => AssessmentAttempt::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                ]);
                $this->recordEvent($attempt, 'submitted');
                $attempt->handleTrainingQuizSubmission();
            });
            $attempt->refresh();

            return ApiResponse::success(AssessmentAttemptResource::make($attempt), 'Quiz submitted.');
        }

        $attempt->load(['window.assessment', 'user.employee.department']);
        $isAppraisal = $attempt->window->assessment->type === 'appraisal';

        if ($isAppraisal) {
            $request->validate([
                'employee_comment' => ['nullable', 'string', 'max:2000'],
            ]);

            // The review flow is fixed on first submission, so a later change of the setting doesn't strand it.
            $reviewFlow = $attempt->review_flow ?? AppraisalEndorsement::reviewFlow();

            $employee = $attempt->user->employee;
            // Nobody above them (the top of the organization): straight to HR, no appraiser step.
            if ($employee && !app(AppraisalEndorsement::class)->appraiserOf($employee)) {
                DB::transaction(function () use ($attempt, $request, $reviewFlow) {
                    $attempt->update([
                        'status'           => AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                        'review_flow'      => $reviewFlow,
                        'submitted_at'     => now(),
                        'employee_comment' => $request->employee_comment,
                    ]);

                    $this->recordEvent($attempt, 'submitted', $request->employee_comment);
                    $this->recordEvent($attempt, 'supervisor_confirmed', 'Auto-confirmed (nobody above them)');

                    activity('appraisals')->performedOn($attempt)->log("Appraisal auto-confirmed (top-level HOD): {$attempt->user->name}");
                });

                return ApiResponse::success(
                    AssessmentAttemptResource::make($attempt),
                    'Submitted and forwarded directly to HR for finalization.'
                );
            }

            // Regular employee OR HOD whose parent dept HOD must review
            DB::transaction(function () use ($attempt, $request, $reviewFlow) {
                $attempt->update([
                    'status'           => AssessmentAttempt::STATUS_PENDING_SUPERVISOR,
                    'review_flow'      => $reviewFlow,
                    'submitted_at'     => now(),
                    'employee_comment' => $request->employee_comment,
                ]);

                $this->recordEvent($attempt, 'submitted', $request->employee_comment);

                activity('appraisals')->performedOn($attempt)->log("Appraisal submitted for supervisor review: {$attempt->user->name}");
            });

            $supervisor = $this->findSupervisorUser($attempt);
            if ($supervisor) {
                $supervisor->notify(new AppraisalNotification(
                    type:     'appraisal_submitted',
                    subject:  "{$attempt->user->name}'s Appraisal Awaits Your Review",
                    greeting: "Hello {$supervisor->name},",
                    lines:    [
                        "{$attempt->user->name} has submitted their appraisal for \"{$attempt->window->title}\" and it is pending your review.",
                    ],
                ));
            }

            $message = $supervisor ? "Submitted for {$supervisor->name}'s review." : 'Submitted for supervisor review.';

            return ApiResponse::success(AssessmentAttemptResource::make($attempt), $message);
        }

        DB::transaction(function () use ($attempt) {
            $attempt->update([
                'status'       => AssessmentAttempt::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);

            $this->recordEvent($attempt, 'submitted');
        });

        return ApiResponse::success(AssessmentAttemptResource::make($attempt), 'Assessment submitted.');
    }

    // ── Appraisal-specific supervisor actions ─────────────────────────────────

    /**
     * Supervisor sees their direct reports' pending appraisal attempts.
     * Supports: search (name/email), status, from/to (submitted_at), window_uuid.
     */
    public function supervisorPending(Request $request): AnonymousResourceCollection
    {
        // The people the signed-in user appraises: those whose supervisor they are (set as Reports to, or in a unit
        // they head).
        $me = auth()->user()?->employee;
        $reportIds = $me ? app(\App\Services\Org\ReportingLine::class)->reportsOf($me)->pluck('id')->all() : [];

        $query = AssessmentAttempt::whereIn('status', [
                AssessmentAttempt::STATUS_PENDING_SUPERVISOR,
                AssessmentAttempt::STATUS_PENDING_EMPLOYEE_ACKNOWLEDGMENT,
                AssessmentAttempt::STATUS_PENDING_ENDORSEMENT,
                AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                AssessmentAttempt::STATUS_PENDING_SIGNATURES,
                AssessmentAttempt::STATUS_RETURNED,
                AssessmentAttempt::STATUS_COMPLETED,
            ])
            ->whereHas('window.assessment', fn ($q) => $q->where('type', 'appraisal'))
            ->whereHas('user.employee', fn ($e) => $e->whereIn('id', $reportIds ?: [0]))
            ->with(['user.employee.department', 'user.employee.photo', 'user.employee.rank', 'window.assessment', 'window.questionUsages', 'responses.question.questionCategory', 'responses.revisions.reviser', 'events.actor', 'kpis', 'nextKpis', 'trainings'])
            ->latest('submitted_at');

        if ($search = trim((string) $request->input('search'))) {
            $query->whereHas('user', fn ($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
            );
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->input('from')) {
            $query->whereDate('submitted_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->whereDate('submitted_at', '<=', $to);
        }

        if ($windowUuid = $request->input('window_uuid')) {
            $query->whereHas('window', fn ($q) => $q->where('uuid', $windowUuid));
        }

        return AssessmentAttemptResource::collection(
            $query->paginate($request->input('per_page', 20))
        );
    }

    /**
     * Supervisor overrides one or more employee responses (appraisal only).
     * Original employee answers are preserved; override values sit in separate columns.
     */
    public function supervisorOverrideResponses(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeSupervisor($attempt);

        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SUPERVISOR) {
            return response()->json(['message' => 'This attempt is not pending supervisor review.'], 422);
        }

        if (!$attempt->isAppraisal()) {
            return response()->json(['message' => 'Answer overrides are only allowed for appraisal assessments.'], 422);
        }

        $request->validate([
            'responses'                        => ['required', 'array', 'min:1'],
            'responses.*.question_uuid'        => ['required', 'exists:questions,uuid'],
            'responses.*.answer'               => ['nullable', 'string'],
            'responses.*.score'                => ['nullable', 'integer'],
            'responses.*.justification'        => ['nullable', 'string', 'max:2000'],
        ]);

        if ($this->notIn($request->responses, $attempt, QuestionUsage::RESPONDENT_EMPLOYEE)) {
            return response()->json(['message' => 'Only the employee\'s answers can be changed here; answer your own section separately.'], 422);
        }

        $attempt->load('responses.question');

        $changed = 0;
        $direct  = $attempt->isDirectReview();

        DB::transaction(function () use ($request, $attempt, $direct, &$changed) {
            foreach ($request->responses as $item) {
                $response = $attempt->responses->first(
                    fn ($r) => $r->question?->uuid === $item['question_uuid']
                );

                if (!$response) continue;

                // Every change is kept for HR: what stood before (the previous change, else the employee's answer).
                $previousAnswer = $response->supervisor_updated_at ? $response->supervisor_answer : $response->answer;
                $previousScore  = $response->supervisor_updated_at ? $response->supervisor_score : $response->score;
                $newAnswer      = $item['answer'] ?? null;
                $newScore       = isset($item['score']) ? (int) $item['score'] : null;
                if ($previousAnswer !== $newAnswer || $previousScore !== $newScore) {
                    QuestionResponseRevision::create([
                        'question_response_id'  => $response->id,
                        'assessment_attempt_id' => $attempt->id,
                        'previous_answer'       => $previousAnswer,
                        'previous_score'        => $previousScore,
                        'answer'                => $newAnswer,
                        'score'                 => $newScore,
                        'justification'         => $item['justification'] ?? null,
                        'revised_by'            => auth()->id(),
                    ]);
                }

                $response->update([
                    'supervisor_answer'         => $item['answer'] ?? null,
                    'supervisor_score'          => $item['score'] ?? null,
                    'supervisor_id'             => auth()->id(),
                    'supervisor_updated_at'     => now(),
                    'supervisor_justification'  => $item['justification'] ?? null,
                ]);

                $changed++;
            }

            // Direct flow: the appraiser keeps it until they confirm; the employee isn't shown the changes.
            if ($direct) {
                $this->recordEvent($attempt, 'answers_edited', "{$changed} answer(s) updated by supervisor");
                activity('appraisals')->performedOn($attempt)
                    ->withProperties(['changed' => $changed])
                    ->log("Supervisor updated {$changed} answer(s) for {$attempt->user->name}");

                return;
            }

            $attempt->update(['status' => AssessmentAttempt::STATUS_PENDING_EMPLOYEE_ACKNOWLEDGMENT]);

            $this->recordEvent(
                $attempt,
                'answers_edited',
                "{$changed} answer(s) updated by supervisor — sent to employee for acknowledgment",
            );

            activity('appraisals')->performedOn($attempt)
                ->withProperties(['changed' => $changed])
                ->log("Supervisor updated {$changed} answer(s) for {$attempt->user->name} — awaiting employee acknowledgment");
        });

        if ($direct) {
            $attempt->load('responses.question', 'responses.revisions.reviser');

            return ApiResponse::success(
                AssessmentAttemptResource::make($attempt),
                "{$changed} answer(s) saved. Confirm the appraisal to send it on."
            );
        }

        $attempt->loadMissing('user');
        $attempt->user->notify(new AppraisalNotification(
            type:     'appraisal_review_required',
            subject:  'Your HOD Has Made Changes to Your Appraisal',
            greeting: "Hello {$attempt->user->name},",
            lines:    [
                "Your HOD has updated {$changed} response(s) on your appraisal for \"{$attempt->window->title}\".",
                'Please log in to review the changes and accept or disagree.',
            ],
        ));

        $attempt->load('responses.question');

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            "{$changed} answer(s) saved. The employee has been notified to review and accept the changes."
        );
    }

    /**
     * Supervisor answers their own section of the appraisal (the template's supervisor questions), while it is with them.
     * One answer per question, whoever answers it (the appraiser, or HR acting for them).
     */
    public function supervisorAssessment(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeSupervisor($attempt);

        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SUPERVISOR) {
            return response()->json(['message' => 'This attempt is not pending supervisor review.'], 422);
        }

        $request->validate([
            'responses'                 => ['required', 'array', 'min:1'],
            'responses.*.question_uuid' => ['required', 'exists:questions,uuid'],
            'responses.*.answer'        => ['nullable', 'string'],
            'responses.*.score'         => ['nullable', 'integer'],
        ]);

        if ($this->notIn($request->responses, $attempt, QuestionUsage::RESPONDENT_SUPERVISOR)) {
            return response()->json(['message' => 'These are not supervisor questions on this appraisal.'], 422);
        }

        DB::transaction(function () use ($request, $attempt) {
            foreach ($request->responses as $item) {
                QuestionResponse::updateOrCreate(
                    [
                        'question_id'      => Question::where('uuid', $item['question_uuid'])->value('id'),
                        'respondable_type' => AssessmentAttempt::class,
                        'respondable_id'   => $attempt->id,
                    ],
                    [
                        'user_id' => auth()->id(),
                        'answer'  => $item['answer'] ?? null,
                        'score'   => $item['score'] ?? null,
                    ]
                );
            }

            activity('appraisals')->performedOn($attempt)
                ->withProperties(['answered' => count($request->responses)])
                ->log("Supervisor answered their section for {$attempt->user->name}");
        });

        $attempt->load('responses.question', 'window.questionUsages');

        return ApiResponse::success(AssessmentAttemptResource::make($attempt), 'Your answers have been saved.');
    }

    /**
     * Supervisor confirms the appraisal → moves to supervisor_confirmed (visible to HR).
     */
    public function supervisorConfirm(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeSupervisor($attempt);

        $request->validate([
            'supervisor_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SUPERVISOR) {
            return response()->json(['message' => 'This attempt is not pending supervisor review.'], 422);
        }

        if ($unanswered = $this->unanswered($attempt, QuestionUsage::RESPONDENT_SUPERVISOR)) {
            return response()->json([
                'message'    => "Please answer all your questions before confirming. {$unanswered} question(s) still unanswered.",
                'unanswered' => $unanswered,
            ], 422);
        }

        if ($attempt->isDirectReview()) {
            return $this->confirmDirect($request, $attempt);
        }

        DB::transaction(function () use ($request, $attempt) {
            $attempt->update([
                'status'             => AssessmentAttempt::STATUS_PENDING_EMPLOYEE_ACKNOWLEDGMENT,
                'supervisor_id'      => auth()->id(),
                'supervisor_comment' => $request->supervisor_comment,
            ]);

            $this->recordEvent($attempt, 'supervisor_confirmed', $request->supervisor_comment);

            activity('appraisals')->performedOn($attempt)->log("Supervisor confirmed appraisal for {$attempt->user->name} — awaiting employee acknowledgment");
        });

        $attempt->loadMissing('user');
        $attempt->user->notify(new AppraisalNotification(
            type:     'appraisal_review_required',
            subject:  'Your Supervisor Has Reviewed Your Appraisal',
            greeting: "Hello {$attempt->user->name},",
            lines:    [
                "Your supervisor has reviewed and confirmed your appraisal for \"{$attempt->window->title}\".",
                'Please log in to review and accept to forward it to HR.',
            ],
        ));

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Appraisal confirmed. Employee will be notified to review and accept the changes.'
        );
    }

    /**
     * Direct flow: the appraiser's confirmation sends it on (endorsement, else HR) without the employee's
     * acknowledgment. The employee is told only that it has moved on.
     */
    private function confirmDirect(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $endorsing = DB::transaction(function () use ($request, $attempt) {
            $attempt->update([
                'supervisor_id'      => auth()->id(),
                'supervisor_comment' => $request->supervisor_comment,
            ]);

            $this->recordEvent($attempt, 'supervisor_confirmed', $request->supervisor_comment);

            activity('appraisals')->performedOn($attempt)->log("Supervisor confirmed appraisal for {$attempt->user->name}");

            return app(AppraisalEndorsement::class)->forward($attempt, auth()->user());
        });

        $attempt->loadMissing('user');
        $attempt->user->notify(new AppraisalNotification(
            type:     'appraisal_reviewed',
            subject:  'Your Appraisal Has Been Reviewed',
            greeting: "Hello {$attempt->user->name},",
            lines:    [
                "Your appraisal for \"{$attempt->window->title}\" has been reviewed by your supervisor and sent on for finalization.",
                'You will be notified when it has been finalized.',
            ],
        ));

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt->fresh()),
            $endorsing ? 'Appraisal confirmed and sent for endorsement.' : 'Appraisal confirmed and forwarded to HR.'
        );
    }

    /**
     * Supervisor returns the appraisal to the employee for revision.
     */
    public function supervisorReturn(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeSupervisor($attempt);

        $request->validate([
            'supervisor_comment' => ['required', 'string', 'max:2000'],
        ]);

        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SUPERVISOR) {
            return response()->json(['message' => 'This attempt is not pending supervisor review.'], 422);
        }

        DB::transaction(function () use ($request, $attempt) {
            $attempt->update([
                'status'             => AssessmentAttempt::STATUS_RETURNED,
                'supervisor_id'      => auth()->id(),
                'supervisor_comment' => $request->supervisor_comment,
            ]);

            $this->recordEvent($attempt, 'returned', $request->supervisor_comment);

            activity('appraisals')->performedOn($attempt)->log("Supervisor returned appraisal to {$attempt->user->name} for revision");
        });

        $attempt->loadMissing('user');
        $attempt->user->notify(new AppraisalNotification(
            type:     'appraisal_returned',
            subject:  'Your Appraisal Has Been Returned for Revision',
            greeting: "Hello {$attempt->user->name},",
            lines:    [
                "Your supervisor has returned your appraisal for \"{$attempt->window->title}\" for revision.",
                "Reason: {$request->supervisor_comment}",
                'Please log in to make the necessary revisions and resubmit.',
            ],
        ));

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Appraisal returned to employee for revision.'
        );
    }

    /**
     * Employee disagrees with supervisor changes → returns to pending_supervisor for the supervisor to revise or re-confirm.
     * @throws Throwable
     */
    public function employeeDisagree(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        DB::beginTransaction();
        try {
            $this->authorizeAttempt($attempt);

            if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_EMPLOYEE_ACKNOWLEDGMENT) {
                return response()->json(['message' => 'No supervisor changes are pending your acknowledgment.'], 422);
            }

            $request->validate([
                'comment' => ['required', 'string', 'max:2000'],
            ]);

            $attempt->update(['status' => AssessmentAttempt::STATUS_PENDING_SUPERVISOR]);

            $this->recordEvent($attempt, 'employee_disagreed', $request->comment);

            activity('appraisals')->performedOn($attempt)->log("Employee disagreed with supervisor changes: {$attempt->user->name}");

            DB::commit();

            $supervisor = $this->findSupervisorUser($attempt);
            if ($supervisor) {
                $supervisor->notify(new AppraisalNotification(
                    type:     'appraisal_employee_disagreed',
                    subject:  "{$attempt->user->name} Disagreed with Your Appraisal Changes",
                    greeting: "Hello {$supervisor->name},",
                    lines:    [
                        "{$attempt->user->name} has disagreed with the changes you made to their appraisal for \"{$attempt->window->title}\".",
                        "Their reason: {$request->comment}",
                        'Please log in to review their feedback and revise or re-confirm.',
                    ],
                ));
            }

            return ApiResponse::success(
                AssessmentAttemptResource::make($attempt),
                'Your disagreement has been recorded. The appraisal has been returned to your supervisor.'
            );
        }catch (Exception $exception){
            DB::rollBack();

            Log::error('Disagree', [$exception]);
            return ApiResponse::error('Could not save changes');
        }
    }

    /**
     * Employee acknowledges supervisor changes → moves to supervisor_confirmed (visible to HR).
     */
    public function employeeAcknowledge(AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeAttempt($attempt);

        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_EMPLOYEE_ACKNOWLEDGMENT) {
            return response()->json(['message' => 'No supervisor changes are pending your acknowledgment.'], 422);
        }

        $this->recordEvent($attempt, 'employee_acknowledged');
        activity('appraisals')->performedOn($attempt)->log("Employee acknowledged supervisor changes: {$attempt->user->name}");

        // The heads above the appraiser endorse it first, when set up; else straight to HR.
        if (app(AppraisalEndorsement::class)->forward($attempt, auth()->user())) {
            return ApiResponse::success(
                AssessmentAttemptResource::make($attempt->fresh()),
                'Changes acknowledged. Your appraisal has gone to the heads above your supervisor for endorsement.'
            );
        }

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Changes acknowledged. Your appraisal has been forwarded to HR.'
        );
    }

    /**
     * Employee digitally signs the finalized appraisal outcome.
     * If the supervisor has already signed, the appraisal moves to completed.
     */
    public function employeeSign(AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeAttempt($attempt);

        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SIGNATURES) {
            return response()->json(['message' => 'This appraisal is not pending signatures.'], 422);
        }

        DB::transaction(function () use ($attempt) {
            $attempt->update(['employee_signed_at' => now()]);

            $this->recordEvent($attempt, 'employee_signed');

            if ($attempt->supervisor_signed_at) {
                $attempt->update(['status' => AssessmentAttempt::STATUS_COMPLETED]);
            }

            activity('appraisals')->performedOn($attempt)->log("Employee signed appraisal: {$attempt->user->name}");
        });

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            $attempt->status === AssessmentAttempt::STATUS_COMPLETED
                ? 'Appraisal signed and completed.'
                : 'Appraisal signed. Awaiting supervisor signature.'
        );
    }

    /**
     * Supervisor digitally signs the finalized appraisal outcome.
     * If the employee has already signed, the appraisal moves to completed.
     */
    public function supervisorSign(AssessmentAttempt $attempt): JsonResponse
    {
        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SIGNATURES) {
            return response()->json(['message' => 'This appraisal is not pending signatures.'], 422);
        }

        $supervisorUser = $this->findSupervisorUser($attempt);
        if (!$supervisorUser || $supervisorUser->id !== auth()->id()) {
            return response()->json(['message' => 'You are not the supervisor for this appraisal.'], 403);
        }

        DB::transaction(function () use ($attempt) {
            $attempt->update([
                'supervisor_signed_at' => now(),
                'supervisor_id'        => $attempt->supervisor_id ?? auth()->id(),
            ]);

            $this->recordEvent($attempt, 'supervisor_signed');

            if ($attempt->employee_signed_at) {
                $attempt->update(['status' => AssessmentAttempt::STATUS_COMPLETED]);
            }

            activity('appraisals')->performedOn($attempt)->log("Supervisor signed appraisal for {$attempt->user->name}");
        });

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            $attempt->status === AssessmentAttempt::STATUS_COMPLETED
                ? 'Appraisal signed and completed.'
                : 'Appraisal signed. Awaiting employee signature.'
        );
    }

    // ── HR actions ────────────────────────────────────────────────────────────

    /**
     * HR sees all appraisals confirmed by supervisor.
     * Supports: search (name/email), status, from/to (supervisor_confirmed_at), window_uuid.
     */
    public function hrPending(Request $request): AnonymousResourceCollection
    {
        $query = AssessmentAttempt::whereIn('status', [
                AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                AssessmentAttempt::STATUS_PENDING_SIGNATURES,
                AssessmentAttempt::STATUS_COMPLETED,
            ])
            ->whereHas('window.assessment', fn ($q) => $q->where('type', 'appraisal'))
            ->whereDoesntHave('user.roles', fn ($q) => $q->where('name', 'hr'))
            ->with(['user', 'supervisor', 'window.assessment', 'window.questionUsages', 'responses.question.questionCategory', 'responses.revisions.reviser', 'events.actor', 'kpis', 'nextKpis', 'trainings'])
            ->latest('supervisor_confirmed_at');

        if ($search = trim((string) $request->input('search'))) {
            $query->whereHas('user', fn ($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
            );
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->input('from')) {
            $query->whereDate('supervisor_confirmed_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->whereDate('supervisor_confirmed_at', '<=', $to);
        }

        if ($windowUuid = $request->input('window_uuid')) {
            $query->whereHas('window', fn ($q) => $q->where('uuid', $windowUuid));
        }

        return AssessmentAttemptResource::collection(
            $query->paginate($request->input('per_page', 20))
        );
    }

    /**
     * HR finalises the appraisal → pending_signatures.
     * Scores are frozen here; both employee and supervisor must sign to complete.
     */
    public function hrComplete(AssessmentAttempt $attempt): JsonResponse
    {
        if ($attempt->user_id === auth()->id()) {
            return response()->json(['message' => 'You cannot finalise your own appraisal.'], 403);
        }

        if ($attempt->status !== AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED) {
            return response()->json(['message' => 'This appraisal has not been confirmed by a supervisor yet.'], 422);
        }

        DB::transaction(function () use ($attempt) {
            $attempt->update([
                'status'       => AssessmentAttempt::STATUS_PENDING_SIGNATURES,
                ...$this->computeScores($attempt),
                'finalized_at' => now(),
            ]);

            $this->recordEvent($attempt, 'hr_completed');

            activity('appraisals')->performedOn($attempt)->log("HR finalized appraisal for {$attempt->user->name} — pending signatures");
        });

        $attempt->loadMissing('user');

        $attempt->user->notify(new AppraisalNotification(
            type:     'appraisal_completed',
            subject:  'Your Appraisal Has Been Finalized — Signature Required',
            greeting: "Hello {$attempt->user->name},",
            lines:    [
                "Your appraisal for \"{$attempt->window->title}\" has been reviewed and finalized by HR.",
                'Please log in to review the outcome and sign to complete the process.',
            ],
        ));

        $supervisor = $this->findSupervisorUser($attempt);
        if ($supervisor) {
            $supervisor->notify(new AppraisalNotification(
                type:     'appraisal_completed',
                subject:  "Appraisal Signature Required: {$attempt->user->name}",
                greeting: "Hello {$supervisor->name},",
                lines:    [
                    "The appraisal for {$attempt->user->name} (\"{$attempt->window->title}\") has been finalized by HR.",
                    'Please log in to review the outcome and add your signature.',
                ],
            ));
        }

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Appraisal finalized. Employee and supervisor have been notified to sign.'
        );
    }

    /**
     * Generate a PDF report for a single appraisal attempt.
     */
    public function hrPrintPdf(AssessmentAttempt $attempt)
    {
        return $this->pdf($attempt);
    }

    /**
     * PDF download shared across employee, supervisor, and HR.
     * Only available once the appraisal is finalized (pending_signatures or completed).
     */
    public function printPdf(AssessmentAttempt $attempt)
    {
        // Authorization: own attempt, supervisor, or HR/admin
        $userId = auth()->id();
        $isEmployee  = $attempt->user_id === $userId;
        $isHrOrAdmin = auth()->user()->canAny(['finalize-appraisals', 'view-hr-appraisals']);

        if (!$isEmployee && !$isHrOrAdmin) {
            $supervisorUser = $this->findSupervisorUser($attempt);
            if (!$supervisorUser || $supervisorUser->id !== $userId) {
                abort(403);
            }
        }

        if (!in_array($attempt->status, [
            AssessmentAttempt::STATUS_PENDING_SIGNATURES,
            AssessmentAttempt::STATUS_COMPLETED,
        ])) {
            return response()->json(['message' => 'PDF is only available after HR finalizes the appraisal.'], 422);
        }

        return $this->pdf($attempt);
    }

    /**
     * The appraisal report. For the employee in the direct review flow it shows their own answers, the final scores
     * and a timeline without the appraiser's changes or comment; everyone else also gets each change to the answers.
     */
    private function pdf(AssessmentAttempt $attempt)
    {
        $attempt->load([
            'user.employee.department',
            'user.employee.rank',
            'supervisor',
            'window.assessment',
            'window.questionUsages.question.questionCategory',
            'responses.question.questionCategory',
            'responses.revisions.reviser',
            'events.actor',
            'kpis',
            'nextKpis',
            'trainings',
        ]);

        $hide = $attempt->hidesReviewFrom(auth()->id());

        // Build ordered responses using the window question snapshot (the employee's section)
        $orderedQuestions = $attempt->questionUsagesFor(QuestionUsage::RESPONDENT_EMPLOYEE)->sortBy('order')->values();
        $responsesByQuestionId = $attempt->responses->keyBy('question_id');

        $responses = $orderedQuestions->map(function ($usage) use ($responsesByQuestionId, $hide) {
            $r = $responsesByQuestionId->get($usage->question_id);
            return [
                'question_text'     => $usage->question->text,
                'category'          => $usage->question->questionCategory?->name ?? 'General',
                'answer'            => $r?->answer,
                // The employee's copy has no per-question scores: next to the final score they'd hint at changes.
                'self_score'        => $hide ? null : $r?->score,
                'supervisor_answer' => $hide ? null : $r?->supervisor_answer,
                'supervisor_score'  => $hide ? null : $r?->supervisor_score,
                'revisions'         => $hide ? collect() : ($r?->revisions ?? collect()),
            ];
        });

        // The supervisor's own section (not on the employee's copy in the direct flow)
        $supervisorResponses = $attempt->showsSupervisorSectionTo(auth()->id())
            ? $attempt->questionUsagesFor(QuestionUsage::RESPONDENT_SUPERVISOR)->sortBy('order')->values()
                ->map(fn ($usage) => [
                    'question_text' => $usage->question->text,
                    'category'      => $usage->question->questionCategory?->name ?? 'General',
                    'answer'        => $responsesByQuestionId->get($usage->question_id)?->answer,
                    'score'         => $responsesByQuestionId->get($usage->question_id)?->score,
                ])
            : collect();

        // Company info from settings
        $settingKeys = ['company.name', 'company.abbreviation', 'company.tagline', 'company.logo_url'];
        $settings = \App\Models\Config\Setting::whereIn('key', $settingKeys)
            ->get()
            ->mapWithKeys(fn ($s) => [ltrim(strstr($s->key, '.'), '.') => $s->value]);

        // Embed logo as base64 so DomPDF can render it without HTTP
        $logoBase64 = null;
        if ($logoKey = $settings->get('logo_url')) {
            try {
                $logoContent = Storage::disk('common')->get($logoKey);
                if ($logoContent) {
                    $mime = Storage::disk('common')->mimeType($logoKey) ?: 'image/png';
                    $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode($logoContent);
                }
            } catch (Throwable) {
                // Logo unavailable — render without it
            }
        }

        $hrFinalizer = $attempt->events->firstWhere('event_type', 'hr_completed')?->actor;

        $pdf = Pdf::loadView('appraisal.pdf', [
            'attempt'      => $attempt,
            'responses'    => $responses,
            'supervisorResponses' => $supervisorResponses,
            'kpis'         => $attempt->kpis,
            'nextKpis'     => $attempt->nextKpis,
            'trainings'    => $attempt->trainings,
            'events'       => $attempt->eventsVisibleTo(auth()->id()),
            'company'      => $settings,
            'logoBase64'   => $logoBase64,
            'hrFinalizer'  => $hrFinalizer,
            'hideReview'   => $hide,
        ])->setPaper('a4', 'portrait');

        $filename = 'appraisal-' . str($attempt->user->name)->slug() . '-' . now()->format('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Export HR appraisal list to Excel. Applies the same filters as hrPending.
     */
    public function hrExport(Request $request): BinaryFileResponse
    {
        $query = AssessmentAttempt::whereIn('status', [
                AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                AssessmentAttempt::STATUS_PENDING_SIGNATURES,
                AssessmentAttempt::STATUS_COMPLETED,
            ])
            ->whereHas('window.assessment', fn ($q) => $q->where('type', 'appraisal'))
            ->whereDoesntHave('user.roles', fn ($q) => $q->where('name', 'hr'))
            ->with(['user.employee.department', 'supervisor', 'window.assessment'])
            ->latest('supervisor_confirmed_at');

        if ($search = trim((string) $request->input('search'))) {
            $query->whereHas('user', fn ($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
            );
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->input('from')) {
            $query->whereDate('supervisor_confirmed_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->whereDate('supervisor_confirmed_at', '<=', $to);
        }

        if ($windowUuid = $request->input('window_uuid')) {
            $query->whereHas('window', fn ($q) => $q->where('uuid', $windowUuid));
        }

        $filename = 'appraisals-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new AppraisalExport($query->get()), $filename);
    }

    // ── KPIs ──────────────────────────────────────────────────────────────────

    /**
     * Return the saved KPI library for the department the attempt's employee belongs to.
     * Used by the supervisor to pick from previously-entered descriptions.
     */
    public function departmentKpis(AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeSupervisor($attempt);

        $attempt->load('user.employee');
        $departmentId = $attempt->user?->employee?->department_id;

        if (!$departmentId) {
            return ApiResponse::success([]);
        }

        $kpis = JobKpi::libraryFor($departmentId);

        return ApiResponse::success($kpis);
    }

    /**
     * Supervisor syncs job-description-specific KPIs for an appraisal.
     * Replaces all existing KPIs with the submitted list and upserts new
     * descriptions into the department's KPI library for future reuse.
     */
    public function syncKpis(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorizeSupervisor($attempt);

        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SUPERVISOR) {
            return response()->json(['message' => 'KPIs can only be edited while the appraisal is pending supervisor review.'], 422);
        }

        $request->validate([
            // current: the period under review (with actuals); next: the employee's KPI plan for the next period
            'period'             => ['nullable', 'in:current,next'],
            'kpis'               => ['present', 'array'],
            'kpis.*.description' => ['required', 'string', 'max:1000'],
            'kpis.*.target'      => ['required', 'numeric', 'min:0'],
            'kpis.*.actual'      => ['nullable', 'numeric', 'min:0'],
        ]);

        $descriptions = array_map(fn ($k) => strtolower(trim($k['description'])), $request->kpis);
        if (count($descriptions) !== count(array_unique($descriptions))) {
            return response()->json(['message' => 'Duplicate KPI descriptions are not allowed in the same appraisal.'], 422);
        }

        $attempt->load('user.employee', 'window');
        $employee = $attempt->user?->employee;

        if ($request->input('period') === 'next') {
            if (!$employee) {
                return response()->json(['message' => 'This appraisal has no employee record to plan KPIs for.'], 422);
            }
            [$from, $to] = $attempt->nextPeriod();
            DB::transaction(fn () => EmployeeKpi::replacePlan($employee, $from, $to, $request->kpis, auth()->id(), $attempt->id));

            return ApiResponse::success(EmployeeKpiResource::collection($attempt->nextKpis()->get()), 'KPIs saved.');
        }

        DB::transaction(function () use ($request, $attempt, $employee) {
            $attempt->kpis()->delete();

            foreach ($request->kpis as $index => $item) {
                AppraisalKpi::create([
                    'assessment_attempt_id' => $attempt->id,
                    'description'           => $item['description'],
                    'target'                => $item['target'],
                    'actual'                => $item['actual'] ?? null,
                    'order'                 => $index,
                ]);

                // Persist unique descriptions to the department library for future reuse
                if ($employee?->department_id) {
                    JobKpi::upsertForDepartment($employee->department_id, $item['description'], auth()->id());
                }
            }
        });

        return ApiResponse::success(
            AppraisalKpiResource::collection($attempt->kpis()->get()),
            'KPIs saved.'
        );
    }

    // ── Appraisal Officer actions (finalises HR employees' appraisals) ────────

    /**
     * Appraisal officer sees supervisor-confirmed appraisals where the employee
     * is an HR staff member (has the 'hr' role).
     * Supports: search (name/email), status, from/to (supervisor_confirmed_at), window_uuid.
     */
    public function appraisalOfficerPending(Request $request): AnonymousResourceCollection
    {
        $query = AssessmentAttempt::whereIn('status', [
                AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                AssessmentAttempt::STATUS_PENDING_SIGNATURES,
                AssessmentAttempt::STATUS_COMPLETED,
            ])
            ->whereHas('window.assessment', fn ($q) => $q->where('type', 'appraisal'))
            ->whereHas('user.roles', fn ($q) => $q->where('name', 'hr'))
            ->with(['user', 'supervisor', 'window.assessment', 'window.questionUsages', 'responses.question.questionCategory', 'responses.revisions.reviser', 'events.actor', 'kpis', 'nextKpis', 'trainings'])
            ->latest('supervisor_confirmed_at');

        if ($search = trim((string) $request->input('search'))) {
            $query->whereHas('user', fn ($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
            );
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->input('from')) {
            $query->whereDate('supervisor_confirmed_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->whereDate('supervisor_confirmed_at', '<=', $to);
        }

        if ($windowUuid = $request->input('window_uuid')) {
            $query->whereHas('window', fn ($q) => $q->where('uuid', $windowUuid));
        }

        return AssessmentAttemptResource::collection(
            $query->paginate($request->input('per_page', 20))
        );
    }

    /**
     * Appraisal officer finalises an HR employee's appraisal.
     */
    public function appraisalOfficerComplete(AssessmentAttempt $attempt): JsonResponse
    {
        if ($attempt->user_id === auth()->id()) {
            return response()->json(['message' => 'You cannot finalise your own appraisal.'], 403);
        }

        if ($attempt->status !== AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED) {
            return response()->json(['message' => 'This appraisal has not been confirmed by a supervisor yet.'], 422);
        }

        DB::transaction(function () use ($attempt) {
            $attempt->update([
                'status'       => AssessmentAttempt::STATUS_PENDING_SIGNATURES,
                ...$this->computeScores($attempt),
                'finalized_at' => now(),
            ]);

            $this->recordEvent($attempt, 'hr_completed');

            activity('appraisals')->performedOn($attempt)->log("Appraisal officer finalized appraisal for {$attempt->user->name} — pending signatures");
        });

        $attempt->loadMissing('user');

        $attempt->user->notify(new AppraisalNotification(
            type:     'appraisal_completed',
            subject:  'Your Appraisal Has Been Finalized — Signature Required',
            greeting: "Hello {$attempt->user->name},",
            lines:    [
                "Your appraisal for \"{$attempt->window->title}\" has been reviewed and finalized.",
                'Please log in to review the outcome and sign to complete the process.',
            ],
        ));

        $supervisor = $this->findSupervisorUser($attempt);
        if ($supervisor) {
            $supervisor->notify(new AppraisalNotification(
                type:     'appraisal_completed',
                subject:  "Appraisal Signature Required: {$attempt->user->name}",
                greeting: "Hello {$supervisor->name},",
                lines:    [
                    "The appraisal for {$attempt->user->name} (\"{$attempt->window->title}\") has been finalized.",
                    'Please log in to review the outcome and add your signature.',
                ],
            ));
        }

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Appraisal finalized. Employee and supervisor have been notified to sign.'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The scores (both sections, self, overall) and KPI achievement rate for an attempt.
     * Frozen at finalization so later template/weight edits don't alter the record.
     */
    private function computeScores(AssessmentAttempt $attempt): array
    {
        $attempt->load(['responses.question', 'window.questionUsages', 'window.assessment', 'kpis']);

        $achievable = $attempt->kpis->filter(fn ($k) => (float) $k->target > 0 && $k->actual !== null);
        $kpiScore   = $achievable->isNotEmpty()
            ? round($achievable->avg(fn ($k) => ((float) $k->actual / (float) $k->target) * 100), 2)
            : null;

        return [...$attempt->sectionScores(), 'kpi_score' => $kpiScore];
    }

    private function recordEvent(
        AssessmentAttempt $attempt,
        string $eventType,
        ?string $comment = null,
        ?int $actorId = null,
    ): void {
        AssessmentAttemptEvent::create([
            'assessment_attempt_id' => $attempt->id,
            'event_type'            => $eventType,
            'actor_id'              => $actorId ?? auth()->id(),
            'comment'               => $comment,
        ]);
    }

    private function authorizeAttempt(AssessmentAttempt $attempt): void
    {
        if ($attempt->user_id !== auth()->id()) {
            abort(403);
        }
    }

    /**
     * The user who reviews this appraisal — mirrors supervisorPending():
     * staff are reviewed by their department's HOD; an HOD is reviewed by the HOD of
     * the parent department. A top-level HOD (no parent) skips review and counts as
     * their own supervisor for the signature step.
     */
    /** The appraiser: the employee's supervisor ("Reports to", else the head of their unit). */
    private function findSupervisorUser(AssessmentAttempt $attempt): ?User
    {
        $attempt->loadMissing('user.employee');
        $employee = $attempt->user?->employee;

        return $employee ? app(AppraisalEndorsement::class)->appraiserOf($employee)?->userAccount : null;
    }

    /**
     * Supervisor-stage actions: the appraisal's supervisor, or HR / super-admin.
     */
    private function authorizeSupervisor(AssessmentAttempt $attempt): void
    {
        if (auth()->user()->can('finalize-appraisals')) {
            return;
        }

        $supervisor = $this->findSupervisorUser($attempt);
        if (!$supervisor || $supervisor->id !== auth()->id()) {
            abort(403, 'You are not the supervisor for this appraisal.');
        }
    }

    private function validateRequiredQuestionsAnswered(AssessmentAttempt $attempt): void
    {
        // Training quiz attempts have no window — questions belong to the assessment directly.
        if ($attempt->isTrainingQuiz()) {
            return;
        }

        if ($unanswered = $this->unanswered($attempt, QuestionUsage::RESPONDENT_EMPLOYEE)) {
            abort(response()->json([
                'message'    => "Please answer all questions before submitting. {$unanswered} question(s) still unanswered.",
                'unanswered' => $unanswered,
            ], 422));
        }
    }

    /** How many of a section's questions have no answer yet (all are required, as for the employee). */
    private function unanswered(AssessmentAttempt $attempt, string $respondent): int
    {
        $questionIds = $attempt->questionUsagesFor($respondent)->pluck('question_id');
        if ($questionIds->isEmpty()) {
            return 0;
        }

        $answeredIds = QuestionResponse::where([
            'respondable_type' => AssessmentAttempt::class,
            'respondable_id'   => $attempt->id,
        ])->whereIn('question_id', $questionIds)
          ->where(fn ($q) => $q->whereNotNull('answer')->orWhereNotNull('score'))
          ->pluck('question_id');

        return $questionIds->diff($answeredIds)->count();
    }

    /** Whether any of these responses is for a question outside the section. */
    private function notIn(array $responses, AssessmentAttempt $attempt, string $respondent): bool
    {
        $allowed = $attempt->questionUsagesFor($respondent)->map(fn ($u) => $u->question?->uuid)->filter();

        return collect($responses)->pluck('question_uuid')->diff($allowed)->isNotEmpty();
    }
}
