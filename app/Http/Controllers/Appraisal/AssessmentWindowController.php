<?php

namespace App\Http\Controllers\Appraisal;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentAttemptResource;
use App\Http\Resources\AssessmentWindowResource;
use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Appraisal\AssessmentWindow;
use App\Models\User;
use App\Notifications\Appraisal\AppraisalNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class AssessmentWindowController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $windows = AssessmentWindow::with(['assessment.jobCategories'])
            ->withCount(['attempts', 'attempts as submitted_count' => fn ($q) => $q->where('status', 'submitted')])
            ->when($request->assessment_uuid, fn ($q, $uuid) =>
                $q->whereHas('assessment', fn ($a) => $a->where('uuid', $uuid))
            )
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->type, fn ($q, $t) =>
                $q->whereHas('assessment', fn ($a) => $a->where('type', $t))
            )
            ->latest()
            ->paginate($request->input('per_page', 15));

        return AssessmentWindowResource::collection($windows);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'assessment_uuid' => ['required', 'exists:assessments,uuid'],
            'title'           => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'start_date'      => ['nullable', 'date'],
            'end_date'        => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $assessment = Assessment::where('uuid', $request->assessment_uuid)->firstOrFail();

        $window = AssessmentWindow::create([
            'assessment_id' => $assessment->id,
            'title'         => $request->title,
            'description'   => $request->description,
            'start_date'    => $request->start_date,
            'end_date'      => $request->end_date,
            'status'        => 'draft',
        ]);

        $window->load('assessment.jobCategories');

        activity('appraisals')->performedOn($window)->log("Created assessment window: {$window->title}");

        return ApiResponse::success(AssessmentWindowResource::make($window), 'Window created.', 201);
    }

    public function show(AssessmentWindow $assessmentWindow): JsonResponse
    {
        $assessmentWindow->load(['assessment.jobCategories', 'assessment.questionUsages']);

        return ApiResponse::success(AssessmentWindowResource::make($assessmentWindow));
    }

    public function update(Request $request, AssessmentWindow $assessmentWindow): JsonResponse
    {
        $request->validate([
            'title'      => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $assessmentWindow->update($request->only(['title', 'description', 'start_date', 'end_date']));

        activity('appraisals')->performedOn($assessmentWindow)->log("Updated assessment window: {$assessmentWindow->title}");

        return ApiResponse::success(AssessmentWindowResource::make($assessmentWindow));
    }

    public function destroy(AssessmentWindow $assessmentWindow): JsonResponse
    {
        if ($assessmentWindow->attempts()->where('status', 'submitted')->exists()) {
            return response()->json(['message' => 'Cannot delete a window with submitted attempts.'], 422);
        }

        $title = $assessmentWindow->title;
        $assessmentWindow->delete();

        activity('appraisals')->log("Deleted assessment window: {$title}");

        return ApiResponse::success([], 'Window deleted.');
    }

    public function open(AssessmentWindow $assessmentWindow): JsonResponse
    {
        $assessmentWindow->load('assessment.questionUsages');

        if ($assessmentWindow->assessment->questionUsages->isEmpty()) {
            return response()->json([
                'message' => 'Cannot open a session with no questions. Add questions to the template first.',
            ], 422);
        }

        $assessmentWindow->update(['status' => 'open']);

        // Snapshot template questions into the session so future template edits
        // have no effect on in-progress attempts.
        $assessmentWindow->snapshotQuestionsFromTemplate();

        activity('appraisals')->performedOn($assessmentWindow)->log("Opened assessment window: {$assessmentWindow->title}");

        if ($assessmentWindow->assessment->type === 'appraisal') {
            $this->notifyEligibleEmployees($assessmentWindow);
        }

        return ApiResponse::success(AssessmentWindowResource::make($assessmentWindow), 'Session opened and questions locked in.');
    }

    public function close(AssessmentWindow $assessmentWindow): JsonResponse
    {
        $assessmentWindow->update(['status' => 'closed']);

        activity('appraisals')->performedOn($assessmentWindow)->log("Closed assessment window: {$assessmentWindow->title}");

        return ApiResponse::success(AssessmentWindowResource::make($assessmentWindow), 'Window closed.');
    }

    public function attempts(Request $request, AssessmentWindow $assessmentWindow): AnonymousResourceCollection
    {
        $attempts = $assessmentWindow->attempts()
            ->with(['user', 'responses.question.questionCategory', 'events', 'kpis', 'supervisor', 'window.questionUsages'])
            ->withCount('responses')
            ->paginate($request->input('per_page', 20));

        return AssessmentAttemptResource::collection($attempts);
    }

    /**
     * Aggregate KPIs for the window: participation rate, dept avg scores,
     * on-time submission rate, and supervisor confirmation lag.
     */
    public function stats(AssessmentWindow $assessmentWindow): JsonResponse
    {
        $assessmentWindow->loadMissing('assessment.jobCategories');

        // ── Participation Rate ────────────────────────────────────────────────
        $jobCategoryIds = $assessmentWindow->assessment->jobCategories->pluck('id');

        $eligibleQuery = DB::table('employees')
            ->join('job_details', 'job_details.employee_id', '=', 'employees.id')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->whereNull('employees.deleted_at')
            ->whereNull('users.deleted_at');

        if ($jobCategoryIds->isNotEmpty()) {
            $eligibleQuery->whereIn('job_details.job_category_id', $jobCategoryIds);
        }

        $eligibleCount  = $eligibleQuery->count('employees.id');
        $submittedCount = $assessmentWindow->attempts()
            ->where('status', '!=', AssessmentAttempt::STATUS_DRAFT)
            ->count();

        $participationRate = $eligibleCount > 0
            ? round(($submittedCount / $eligibleCount) * 100, 1)
            : null;

        // ── Department Average Score (completed attempts only) ────────────────
        $deptScores = $assessmentWindow->attempts()
            ->where('status', AssessmentAttempt::STATUS_COMPLETED)
            ->whereNotNull('score')
            ->with(['user.employee.department'])
            ->get(['id', 'user_id', 'score'])
            ->groupBy(fn ($a) => $a->user?->employee?->department?->name ?? 'Unknown')
            ->map(fn ($g) => round($g->avg('score'), 2))
            ->sortKeys()
            ->all();

        // ── On-Time Submission Rate ───────────────────────────────────────────
        $onTimeRate = null;
        if ($assessmentWindow->end_date) {
            $totalSubmitted = $assessmentWindow->attempts()
                ->whereNotNull('submitted_at')
                ->count();

            $onTime = $assessmentWindow->attempts()
                ->whereNotNull('submitted_at')
                ->where('submitted_at', '<=', $assessmentWindow->end_date)
                ->count();

            $onTimeRate = $totalSubmitted > 0
                ? round(($onTime / $totalSubmitted) * 100, 1)
                : null;
        }

        // ── Supervisor Confirmation Lag ───────────────────────────────────────
        $lagAttempts = $assessmentWindow->attempts()
            ->whereNotNull('submitted_at')
            ->whereNotNull('supervisor_confirmed_at')
            ->get(['submitted_at', 'supervisor_confirmed_at']);

        $avgLagDays = $lagAttempts->isNotEmpty()
            ? round(
                $lagAttempts->avg(
                    fn ($a) => $a->submitted_at->diffInHours($a->supervisor_confirmed_at) / 24
                ),
                1
            )
            : null;

        return ApiResponse::success([
            'eligible_employees'      => $eligibleCount,
            'submitted_count'         => $submittedCount,
            'participation_rate'      => $participationRate,
            'department_avg_scores'   => $deptScores,
            'on_time_submission_rate' => $onTimeRate,
            'avg_supervisor_lag_days' => $avgLagDays,
        ]);
    }

    private function notifyEligibleEmployees(AssessmentWindow $window): void
    {
        $window->loadMissing('assessment.jobCategories');
        $jobCategoryIds = $window->assessment->jobCategories->pluck('id');

        $query = User::join('employees', 'users.employee_id', '=', 'employees.id')
            ->join('job_details', 'job_details.employee_id', '=', 'employees.id')
            ->whereNull('users.deleted_at')
            ->whereNull('employees.deleted_at')
            ->select('users.*');

        if ($jobCategoryIds->isNotEmpty()) {
            $query->whereIn('job_details.job_category_id', $jobCategoryIds);
        }

        $users = $query->get();
        if ($users->isEmpty()) return;

        Notification::send($users, new AppraisalNotification(
            type:     'appraisal_session_opened',
            subject:  "Appraisal Session Now Open: {$window->title}",
            greeting: 'Hello,',
            lines:    [
                "The appraisal session \"{$window->title}\" is now open.",
                'Please log in to complete your self-assessment.',
                $window->end_date
                    ? "Deadline: {$window->end_date->format('d M Y, g:i A')}."
                    : 'No closing date has been set for this session.',
            ],
        ));
    }
}
