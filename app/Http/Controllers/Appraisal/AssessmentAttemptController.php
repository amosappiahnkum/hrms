<?php

namespace App\Http\Controllers\Appraisal;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppraisalKpiResource;
use App\Http\Resources\AssessmentAttemptResource;
use App\Http\Resources\AssessmentWindowResource;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Appraisal\AssessmentAttemptEvent;
use App\Models\Appraisal\AssessmentWindow;
use App\Models\Appraisal\AppraisalKpi;
use App\Models\QuestionBank\Question;
use App\Models\QuestionResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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
        $assessmentWindow->load(['assessment.jobCategories', 'questionUsages.question.options', 'questionUsages.question.dependsOn']);

        $attempt->load(['responses.question']);

        return ApiResponse::success([
            'attempt'         => AssessmentAttemptResource::make($attempt),
            'window'          => AssessmentWindowResource::make($assessmentWindow),
            'requires_approval' => $assessmentWindow->assessment->type === 'appraisal',
            'questions'       => $assessmentWindow->questionUsages->map(fn ($usage) => [
                'uuid'             => $usage->question->uuid,
                'text'             => $usage->question->text,
                'description'      => $usage->question->description,
                'type'             => $usage->question->type,
                'is_required'      => $usage->is_required_override ?? $usage->question->is_required,
                'order'            => $usage->order,
                'depends_on_uuid'  => $usage->question->dependsOn?->uuid,
                'show_when_value'  => $usage->question->show_when_value,
                'options'          => $usage->question->options->map(fn ($o) => [
                    'uuid'         => $o->uuid,
                    'option_text'  => $o->option_text,
                    'option_value' => $o->option_value,
                    'order'        => $o->order,
                ]),
            ])->sortBy('order')->values(),
            'saved_responses' => $attempt->responses->map(fn ($r) => [
                'question_uuid' => $r->question->uuid ?? null,
                'answer'        => $r->answer,
                'score'         => $r->score,
            ]),
        ]);
    }

    /**
     * Read-only view of the authenticated user's submitted responses for a window.
     */
    public function myAttemptReview(AssessmentWindow $assessmentWindow): JsonResponse
    {
        $attempt = AssessmentAttempt::where('assessment_window_id', $assessmentWindow->id)
            ->where('user_id', auth()->id())
            ->with(['responses.question', 'events.actor'])
            ->firstOrFail();

        $assessmentWindow->load(['questionUsages.question']);

        // Build an ordered map of question_uuid → question_text using the window snapshot order
        $orderedQuestions = $assessmentWindow->questionUsages
            ->sortBy('order')
            ->map(fn ($u) => [
                'uuid'  => $u->question->uuid,
                'text'  => $u->question->text,
                'order' => $u->order,
            ])
            ->values();

        // Index responses by question UUID for fast lookup
        $responsesByUuid = $attempt->responses->keyBy(fn ($r) => $r->question?->uuid);

        $responses = $orderedQuestions->map(fn ($q) => [
            'question_uuid' => $q['uuid'],
            'question_text' => $q['text'],
            'answer'        => $responsesByUuid[$q['uuid']]?->answer,
            'score'         => $responsesByUuid[$q['uuid']]?->score,
        ]);

        return ApiResponse::success([
            'status'    => $attempt->status,
            'responses' => $responses,
            'events'    => $attempt->events->map(fn ($e) => [
                'uuid'       => $e->uuid,
                'event_type' => $e->event_type,
                'comment'    => $e->comment,
                'actor'      => $e->actor_id ? ['uuid' => $e->actor?->uuid, 'name' => $e->actor?->name] : null,
                'created_at' => $e->created_at?->toDateTimeString(),
            ]),
        ]);
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

        $attempt->load(['window.assessment', 'user.employee.department']);
        $isAppraisal = $attempt->window->assessment->type === 'appraisal';

        if ($isAppraisal) {
            $request->validate([
                'employee_comment' => ['nullable', 'string', 'max:2000'],
            ]);

            $employee   = $attempt->user->employee;
            $department = $employee?->department;
            $isHod      = $department && (int) $department->hod === (int) $employee?->id;

            // HOD with no parent department → auto-confirm, skip supervisor step
            if ($isHod && !$department->parent_department_id) {
                $attempt->update([
                    'status'           => AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                    'submitted_at'     => now(),
                    'employee_comment' => $request->employee_comment,
                ]);

                $this->recordEvent($attempt, 'submitted', $request->employee_comment);
                $this->recordEvent($attempt, 'supervisor_confirmed', 'Auto-confirmed (top-level HOD)');

                activity('appraisals')->performedOn($attempt)->log("Appraisal auto-confirmed (top-level HOD): {$attempt->user->name}");

                return ApiResponse::success(
                    AssessmentAttemptResource::make($attempt),
                    'Submitted and forwarded directly to HR for finalization.'
                );
            }

            // Regular employee OR HOD whose parent dept HOD must review
            $attempt->update([
                'status'           => AssessmentAttempt::STATUS_PENDING_SUPERVISOR,
                'submitted_at'     => now(),
                'employee_comment' => $request->employee_comment,
            ]);

            $this->recordEvent($attempt, 'submitted', $request->employee_comment);

            activity('appraisals')->performedOn($attempt)->log("Appraisal submitted for supervisor review: {$attempt->user->name}");

            $message = $isHod
                ? 'Submitted for your Dean\'s review.'
                : 'Submitted for supervisor review.';

            return ApiResponse::success(AssessmentAttemptResource::make($attempt), $message);
        }

        $attempt->update([
            'status'       => AssessmentAttempt::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->recordEvent($attempt, 'submitted');

        return ApiResponse::success(AssessmentAttemptResource::make($attempt), 'Assessment submitted.');
    }

    // ── Appraisal-specific supervisor actions ─────────────────────────────────

    /**
     * Supervisor sees their direct reports' pending appraisal attempts.
     */
    public function supervisorPending(): AnonymousResourceCollection
    {
        $supervisorEmployeeId = DB::table('employees')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->where('users.id', auth()->id())
            ->value('employees.id');

        // Departments where the logged-in user is HOD
        $myDeptIds = DB::table('departments')
            ->where('hod', $supervisorEmployeeId)
            ->pluck('id');

        $attempts = AssessmentAttempt::whereIn('status', [
                AssessmentAttempt::STATUS_PENDING_SUPERVISOR,
                AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                AssessmentAttempt::STATUS_RETURNED,
                AssessmentAttempt::STATUS_COMPLETED,
            ])
            ->whereHas('window.assessment', fn ($q) => $q->where('type', 'appraisal'))
            ->where(function ($q) use ($supervisorEmployeeId, $myDeptIds) {

                // Case 1: Regular employee whose department HOD is me.
                // Exclude myself so a HOD never sees their own submission here.
                $q->whereHas('user.employee', function ($e) use ($myDeptIds, $supervisorEmployeeId) {
                    $e->whereIn('department_id', $myDeptIds)
                      ->where('id', '!=', $supervisorEmployeeId);
                });

                // Case 2: HOD of a sub-department where my department is the parent.
                // Their appraisal bypassed their own dept and was routed up to me.
                $q->orWhereHas('user.employee', function ($e) use ($myDeptIds) {
                    $e->whereHas('department', function ($d) use ($myDeptIds) {
                        $d->whereColumn('hod', 'employees.id')           // submitter IS their dept's HOD
                          ->whereIn('parent_department_id', $myDeptIds); // that dept's parent is mine
                    });
                });
            })
            ->with(['user', 'window.assessment', 'responses.question', 'events.actor', 'kpis'])
            ->paginate(50);

        return AssessmentAttemptResource::collection($attempts);
    }

    /**
     * Supervisor overrides one or more employee responses (appraisal only).
     * Original employee answers are preserved; override values sit in separate columns.
     */
    public function supervisorOverrideResponses(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SUPERVISOR) {
            return response()->json(['message' => 'This attempt is not pending supervisor review.'], 422);
        }

        if (!$attempt->isAppraisal()) {
            return response()->json(['message' => 'Answer overrides are only allowed for appraisal assessments.'], 422);
        }

        $request->validate([
            'responses'                 => ['required', 'array', 'min:1'],
            'responses.*.question_uuid' => ['required', 'exists:questions,uuid'],
            'responses.*.answer'        => ['nullable', 'string'],
            'responses.*.score'         => ['nullable', 'integer'],
        ]);

        $attempt->load('responses.question');

        $changed = 0;

        foreach ($request->responses as $item) {
            $response = $attempt->responses->first(
                fn ($r) => $r->question?->uuid === $item['question_uuid']
            );

            if (!$response) continue;

            $response->update([
                'supervisor_answer'     => $item['answer'] ?? null,
                'supervisor_score'      => $item['score'] ?? null,
                'supervisor_id'         => auth()->id(),
                'supervisor_updated_at' => now(),
            ]);

            $changed++;
        }

        $this->recordEvent(
            $attempt,
            'answers_edited',
            "{$changed} answer(s) updated by supervisor",
        );

        activity('appraisals')->performedOn($attempt)
            ->withProperties(['changed' => $changed])
            ->log("Supervisor updated {$changed} answer(s) for {$attempt->user->name}");

        $attempt->load('responses.question');

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            "{$changed} answer(s) updated."
        );
    }

    /**
     * Supervisor confirms the appraisal → moves to supervisor_confirmed (visible to HR).
     */
    public function supervisorConfirm(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $request->validate([
            'supervisor_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SUPERVISOR) {
            return response()->json(['message' => 'This attempt is not pending supervisor review.'], 422);
        }

        $attempt->update([
            'status'                  => AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
            'supervisor_id'           => auth()->id(),
            'supervisor_comment'      => $request->supervisor_comment,
            'supervisor_confirmed_at' => now(),
        ]);

        $this->recordEvent($attempt, 'supervisor_confirmed', $request->supervisor_comment);

        activity('appraisals')->performedOn($attempt)->log("Supervisor confirmed appraisal for {$attempt->user->name}");

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Appraisal confirmed and forwarded to HR.'
        );
    }

    /**
     * Supervisor returns the appraisal to the employee for revision.
     */
    public function supervisorReturn(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $request->validate([
            'supervisor_comment' => ['required', 'string', 'max:2000'],
        ]);

        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SUPERVISOR) {
            return response()->json(['message' => 'This attempt is not pending supervisor review.'], 422);
        }

        $attempt->update([
            'status'             => AssessmentAttempt::STATUS_RETURNED,
            'supervisor_id'      => auth()->id(),
            'supervisor_comment' => $request->supervisor_comment,
        ]);

        $this->recordEvent($attempt, 'returned', $request->supervisor_comment);

        activity('appraisals')->performedOn($attempt)->log("Supervisor returned appraisal to {$attempt->user->name} for revision");

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Appraisal returned to employee for revision.'
        );
    }

    // ── HR actions ────────────────────────────────────────────────────────────

    /**
     * HR sees all appraisals confirmed by supervisor.
     */
    public function hrPending(Request $request): AnonymousResourceCollection
    {
        $attempts = AssessmentAttempt::whereIn('status', [
                AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                AssessmentAttempt::STATUS_COMPLETED,
            ])
            ->whereHas('window.assessment', fn ($q) => $q->where('type', 'appraisal'))
            // HR employees' appraisals are routed to the appraisal_officer, not HR itself
            ->whereDoesntHave('user.roles', fn ($q) => $q->where('name', 'hr'))
            ->with(['user', 'supervisor', 'window.assessment', 'responses.question', 'events.actor', 'kpis'])
            ->latest('supervisor_confirmed_at')
            ->paginate($request->input('per_page', 50));

        return AssessmentAttemptResource::collection($attempts);
    }

    /**
     * HR finalises the appraisal → completed.
     */
    public function hrComplete(AssessmentAttempt $attempt): JsonResponse
    {
        if ($attempt->user_id === auth()->id()) {
            return response()->json(['message' => 'You cannot finalise your own appraisal.'], 403);
        }

        if ($attempt->status !== AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED) {
            return response()->json(['message' => 'This appraisal has not been confirmed by a supervisor yet.'], 422);
        }

        $attempt->update(['status' => AssessmentAttempt::STATUS_COMPLETED]);

        $this->recordEvent($attempt, 'hr_completed');

        activity('appraisals')->performedOn($attempt)->log("HR completed appraisal for {$attempt->user->name}");

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Appraisal completed.'
        );
    }

    // ── KPIs ──────────────────────────────────────────────────────────────────

    /**
     * Supervisor syncs job-description-specific KPIs for an appraisal.
     * Replaces all existing KPIs with the submitted list.
     */
    public function syncKpis(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SUPERVISOR) {
            return response()->json(['message' => 'KPIs can only be edited while the appraisal is pending supervisor review.'], 422);
        }

        $request->validate([
            'kpis'                => ['required', 'array'],
            'kpis.*.description'  => ['required', 'string', 'max:1000'],
            'kpis.*.target'       => ['required', 'string', 'max:1000'],
            'kpis.*.actual'       => ['nullable', 'string', 'max:1000'],
        ]);

        $attempt->kpis()->delete();

        foreach ($request->kpis as $index => $item) {
            AppraisalKpi::create([
                'assessment_attempt_id' => $attempt->id,
                'description'           => $item['description'],
                'target'                => $item['target'],
                'actual'                => $item['actual'] ?? null,
                'order'                 => $index,
            ]);
        }

        $attempt->load('kpis');

        return ApiResponse::success(
            AppraisalKpiResource::collection($attempt->kpis),
            'KPIs saved.'
        );
    }

    // ── Appraisal Officer actions (finalises HR employees' appraisals) ────────

    /**
     * Appraisal officer sees supervisor-confirmed appraisals where the employee
     * is an HR staff member (has the 'hr' role).
     */
    public function appraisalOfficerPending(Request $request): AnonymousResourceCollection
    {
        $attempts = AssessmentAttempt::whereIn('status', [
            AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
            AssessmentAttempt::STATUS_COMPLETED,
        ])
            ->whereHas('window.assessment', fn ($q) => $q->where('type', 'appraisal'))
            ->whereHas('user.roles', fn ($q) => $q->where('name', 'hr'))
            ->with(['user', 'supervisor', 'window.assessment', 'responses.question', 'events.actor', 'kpis'])
            ->latest('supervisor_confirmed_at')
            ->paginate($request->input('per_page', 50));

        return AssessmentAttemptResource::collection($attempts);
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

        $attempt->update(['status' => AssessmentAttempt::STATUS_COMPLETED]);

        $this->recordEvent($attempt, 'hr_completed');

        activity('appraisals')->performedOn($attempt)->log("Appraisal officer completed appraisal for {$attempt->user->name}");

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Appraisal completed.'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    private function validateRequiredQuestionsAnswered(AssessmentAttempt $attempt): void
    {
        $window = $attempt->window->load('questionUsages.question');

        // All questions in the session snapshot must be answered before submitting.
        $allQuestionIds = $window->questionUsages->pluck('question.id');

        $answeredIds = QuestionResponse::where([
            'respondable_type' => AssessmentAttempt::class,
            'respondable_id'   => $attempt->id,
        ])->where(fn ($q) => $q->whereNotNull('answer')->orWhereNotNull('score'))
          ->pluck('question_id');

        $unanswered = $allQuestionIds->diff($answeredIds)->count();

        if ($unanswered > 0) {
            abort(response()->json([
                'message'    => "Please answer all questions before submitting. {$unanswered} question(s) still unanswered.",
                'unanswered' => $unanswered,
            ], 422));
        }
    }
}
