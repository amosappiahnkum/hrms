<?php

namespace App\Http\Controllers\Appraisal;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentAttemptResource;
use App\Http\Resources\AssessmentWindowResource;
use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Appraisal\AssessmentWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssessmentWindowController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $windows = AssessmentWindow::with(['assessment.assignable'])
            ->withCount(['attempts', 'attempts as submitted_count' => fn ($q) => $q->where('status', 'submitted')])
            ->when($request->assessment_uuid, fn ($q, $uuid) =>
                $q->whereHas('assessment', fn ($a) => $a->where('uuid', $uuid))
            )
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
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

        $window->load('assessment.assignable');

        return ApiResponse::success(AssessmentWindowResource::make($window), 'Window created.', 201);
    }

    public function show(AssessmentWindow $assessmentWindow): JsonResponse
    {
        $assessmentWindow->load(['assessment.assignable', 'assessment.questionUsages']);

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

        return ApiResponse::success(AssessmentWindowResource::make($assessmentWindow));
    }

    public function destroy(AssessmentWindow $assessmentWindow): JsonResponse
    {
        if ($assessmentWindow->attempts()->where('status', 'submitted')->exists()) {
            return response()->json(['message' => 'Cannot delete a window with submitted attempts.'], 422);
        }

        $assessmentWindow->delete();

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

        return ApiResponse::success(AssessmentWindowResource::make($assessmentWindow), 'Session opened and questions locked in.');
    }

    public function close(AssessmentWindow $assessmentWindow): JsonResponse
    {
        $assessmentWindow->update(['status' => 'closed']);

        return ApiResponse::success(AssessmentWindowResource::make($assessmentWindow), 'Window closed.');
    }

    public function attempts(AssessmentWindow $assessmentWindow): AnonymousResourceCollection
    {
        $attempts = $assessmentWindow->attempts()
            ->with(['responses'])
            ->withCount('responses')
            ->get();

        return AssessmentAttemptResource::collection($attempts);
    }
}
