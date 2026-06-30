<?php

namespace App\Http\Controllers\Appraisal;

use App\Exports\AppraisalExport;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppraisalKpiResource;
use App\Http\Resources\AssessmentAttemptResource;
use App\Http\Resources\AssessmentWindowResource;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Appraisal\AssessmentAttemptEvent;
use App\Models\Appraisal\AssessmentWindow;
use App\Models\Appraisal\AppraisalKpi;
use App\Models\Appraisal\JobKpi;
use App\Models\QuestionBank\Question;
use App\Models\QuestionResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            ->with(['responses.question', 'events.actor', 'kpis'])
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
            'status'       => $attempt->status,
            'score'        => $attempt->score,
            'self_score'   => $attempt->self_score,
            'kpi_score'    => $attempt->kpi_score,
            'finalized_at' => $attempt->finalized_at?->toDateTimeString(),
            'responses'    => $responses,
            'kpis'         => AppraisalKpiResource::collection($attempt->kpis),
            'events'       => $attempt->events->map(fn ($e) => [
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

        // Training quiz: no supervisor stage, just submit and update progress
        if ($attempt->isTrainingQuiz()) {
            $attempt->update([
                'status'       => AssessmentAttempt::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);
            $this->recordEvent($attempt, 'submitted');
            $attempt->handleTrainingQuizSubmission();
            $attempt->refresh();

            return ApiResponse::success(AssessmentAttemptResource::make($attempt), 'Quiz submitted.');
        }

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
     * Supports: search (name/email), status, from/to (submitted_at), window_uuid.
     */
    public function supervisorPending(Request $request): AnonymousResourceCollection
    {
        $supervisorEmployeeId = DB::table('employees')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->where('users.id', auth()->id())
            ->value('employees.id');

        // Departments where the logged-in user is HOD
        $myDeptIds = DB::table('departments')
            ->where('hod', $supervisorEmployeeId)
            ->pluck('id');

        $query = AssessmentAttempt::whereIn('status', [
                AssessmentAttempt::STATUS_PENDING_SUPERVISOR,
                AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                AssessmentAttempt::STATUS_RETURNED,
                AssessmentAttempt::STATUS_COMPLETED,
            ])
            ->whereHas('window.assessment', fn ($q) => $q->where('type', 'appraisal'))
            ->where(function ($q) use ($supervisorEmployeeId, $myDeptIds) {
                $q->whereHas('user.employee', function ($e) use ($myDeptIds, $supervisorEmployeeId) {
                    $e->whereIn('department_id', $myDeptIds)
                      ->where('id', '!=', $supervisorEmployeeId);
                });
                $q->orWhereHas('user.employee', function ($e) use ($myDeptIds) {
                    $e->whereHas('department', function ($d) use ($myDeptIds) {
                        $d->whereColumn('hod', 'employees.id')
                          ->whereIn('parent_department_id', $myDeptIds);
                    });
                });
            })
            ->with(['user', 'window.assessment', 'window.questionUsages', 'responses.question.questionCategory', 'events.actor', 'kpis'])
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
     * Supports: search (name/email), status, from/to (supervisor_confirmed_at), window_uuid.
     */
    public function hrPending(Request $request): AnonymousResourceCollection
    {
        $query = AssessmentAttempt::whereIn('status', [
                AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                AssessmentAttempt::STATUS_COMPLETED,
            ])
            ->whereHas('window.assessment', fn ($q) => $q->where('type', 'appraisal'))
            ->whereDoesntHave('user.roles', fn ($q) => $q->where('name', 'hr'))
            ->with(['user', 'supervisor', 'window.assessment', 'window.questionUsages', 'responses.question.questionCategory', 'events.actor', 'kpis'])
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

        DB::transaction(function () use ($attempt) {
            $scores = $this->computeScores($attempt);

            $attempt->update([
                'status'       => AssessmentAttempt::STATUS_COMPLETED,
                'score'        => $scores['score'],
                'self_score'   => $scores['self_score'],
                'kpi_score'    => $scores['kpi_score'],
                'finalized_at' => now(),
            ]);

            $this->recordEvent($attempt, 'hr_completed');

            activity('appraisals')->performedOn($attempt)->log("HR completed appraisal for {$attempt->user->name}");
        });

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Appraisal completed.'
        );
    }

    /**
     * Generate a PDF report for a single appraisal attempt.
     */
    public function hrPrintPdf(AssessmentAttempt $attempt)
    {
        $attempt->load([
            'user.employee.department',
            'user.employee.rank',
            'supervisor',
            'window.assessment',
            'window.questionUsages.question.questionCategory',
            'responses.question.questionCategory',
            'events.actor',
            'kpis',
        ]);

        // Build ordered responses using the window question snapshot
        $orderedQuestions = $attempt->window->questionUsages
            ->sortBy('order')
            ->values();

        $responsesByQuestionId = $attempt->responses->keyBy('question_id');

        $responses = $orderedQuestions->map(function ($usage) use ($responsesByQuestionId) {
            $r = $responsesByQuestionId->get($usage->question_id);
            return [
                'question_text'     => $usage->question->text,
                'category'          => $usage->question->questionCategory?->name ?? 'General',
                'answer'            => $r?->answer,
                'self_score'        => $r?->score,
                'supervisor_answer' => $r?->supervisor_answer,
                'supervisor_score'  => $r?->supervisor_score,
            ];
        });

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
            } catch (\Throwable) {
                // Logo unavailable — render without it
            }
        }

        $pdf = Pdf::loadView('appraisal.pdf', [
            'attempt'     => $attempt,
            'responses'   => $responses,
            'kpis'        => $attempt->kpis,
            'events'      => $attempt->events,
            'company'     => $settings,
            'logoBase64'  => $logoBase64,
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
        $attempt->load('user.employee');
        $departmentId = $attempt->user?->employee?->department_id;

        if (!$departmentId) {
            return ApiResponse::success([]);
        }

        $kpis = JobKpi::where('department_id', $departmentId)
            ->orderBy('description')
            ->get(['uuid', 'description']);

        return ApiResponse::success($kpis);
    }

    /**
     * Supervisor syncs job-description-specific KPIs for an appraisal.
     * Replaces all existing KPIs with the submitted list and upserts new
     * descriptions into the department's KPI library for future reuse.
     */
    public function syncKpis(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_SUPERVISOR) {
            return response()->json(['message' => 'KPIs can only be edited while the appraisal is pending supervisor review.'], 422);
        }

        $request->validate([
            'kpis'               => ['present', 'array'],
            'kpis.*.description' => ['required', 'string', 'max:1000'],
            'kpis.*.target'      => ['required', 'numeric', 'min:0'],
            'kpis.*.actual'      => ['nullable', 'numeric', 'min:0'],
        ]);

        $descriptions = array_map(fn ($k) => strtolower(trim($k['description'])), $request->kpis);
        if (count($descriptions) !== count(array_unique($descriptions))) {
            return response()->json(['message' => 'Duplicate KPI descriptions are not allowed in the same appraisal.'], 422);
        }

        $attempt->load('user.employee');
        $departmentId = $attempt->user?->employee?->department_id;

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
            if ($departmentId) {
                JobKpi::upsertForDepartment($departmentId, $item['description'], auth()->id());
            }
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
     * Supports: search (name/email), status, from/to (supervisor_confirmed_at), window_uuid.
     */
    public function appraisalOfficerPending(Request $request): AnonymousResourceCollection
    {
        $query = AssessmentAttempt::whereIn('status', [
                AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED,
                AssessmentAttempt::STATUS_COMPLETED,
            ])
            ->whereHas('window.assessment', fn ($q) => $q->where('type', 'appraisal'))
            ->whereHas('user.roles', fn ($q) => $q->where('name', 'hr'))
            ->with(['user', 'supervisor', 'window.assessment', 'window.questionUsages', 'responses.question.questionCategory', 'events.actor', 'kpis'])
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
            $scores = $this->computeScores($attempt);

            $attempt->update([
                'status'       => AssessmentAttempt::STATUS_COMPLETED,
                'score'        => $scores['score'],
                'self_score'   => $scores['self_score'],
                'kpi_score'    => $scores['kpi_score'],
                'finalized_at' => now(),
            ]);

            $this->recordEvent($attempt, 'hr_completed');

            activity('appraisals')->performedOn($attempt)->log("Appraisal officer completed appraisal for {$attempt->user->name}");
        });

        return ApiResponse::success(
            AssessmentAttemptResource::make($attempt),
            'Appraisal completed.'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Compute weighted response scores and KPI achievement rate for an attempt.
     * Frozen at finalization so later template/weight edits don't alter the record.
     *
     * Returns: score (supervisor-adjusted), self_score (employee only), kpi_score (% achievement).
     */
    private function computeScores(AssessmentAttempt $attempt): array
    {
        $attempt->load([
            'responses.question',
            'window.questionUsages',
            'kpis',
        ]);

        $usagesByQuestionId = $attempt->window->questionUsages->keyBy('question_id');

        $totalWeight   = 0;
        $weightedScore = 0;
        $selfWeighted  = 0;

        foreach ($attempt->responses as $response) {
            $effectiveScore = $response->supervisor_score ?? $response->score;
            $selfScore      = $response->score;

            if ($effectiveScore === null && $selfScore === null) {
                continue;
            }

            $usage  = $usagesByQuestionId[$response->question_id] ?? null;
            $weight = (float) ($usage?->custom_weight ?? $response->question?->weight ?? 1);

            $totalWeight   += $weight;
            $weightedScore += ($effectiveScore ?? 0) * $weight;
            $selfWeighted  += ($selfScore ?? 0) * $weight;
        }

        $score     = $totalWeight > 0 ? round($weightedScore / $totalWeight, 2) : null;
        $selfScore = $totalWeight > 0 ? round($selfWeighted / $totalWeight, 2) : null;

        $achievable = $attempt->kpis->filter(fn ($k) => (float) $k->target > 0 && $k->actual !== null);
        $kpiScore   = $achievable->isNotEmpty()
            ? round($achievable->avg(fn ($k) => ((float) $k->actual / (float) $k->target) * 100), 2)
            : null;

        return [
            'score'      => $score,
            'self_score' => $selfScore,
            'kpi_score'  => $kpiScore,
        ];
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

    private function validateRequiredQuestionsAnswered(AssessmentAttempt $attempt): void
    {
        // Training quiz attempts have no window — questions belong to the assessment directly.
        if ($attempt->isTrainingQuiz()) {
            return;
        }

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
