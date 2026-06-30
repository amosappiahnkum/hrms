<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Training\CourseResource;
use App\Models\Training\Course;
use App\Models\Training\CourseEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyCourseController extends Controller
{
    /** List courses the authenticated user is enrolled in. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = auth()->id();

        $enrollments = CourseEnrollment::with(['course' => fn ($q) => $q->withCount('chapters')->with('category')])
            ->where('user_id', $userId)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $v) => $q->whereHas('course', fn ($cq) => $cq->where('title', 'like', "%{$v}%")))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        // Attach enrollment summary onto each course resource
        $courses = $enrollments->getCollection()->map(function (CourseEnrollment $enrollment) {
            $course = $enrollment->course;
            $course->enrollment = [
                'uuid'         => $enrollment->uuid,
                'status'       => $enrollment->status,
                'due_date'     => $enrollment->due_date?->toDateTimeString(),
                'completed_at' => $enrollment->completed_at?->toDateTimeString(),
                'final_score'  => $enrollment->final_score,
            ];
            return $course;
        });

        return CourseResource::collection(
            $enrollments->setCollection($courses)
        );
    }

    /** Full course detail with chapters, materials, and the user's progress markers. */
    public function show(Request $request, Course $course): JsonResponse
    {
        $userId = auth()->id();

        $enrollment = CourseEnrollment::where('course_id', $course->id)
            ->where('user_id', $userId)
            ->with(['chapterProgress', 'materialViews'])
            ->first();

        abort_unless($enrollment, 403, 'You are not enrolled in this course.');

        $course->load(['chapters.materials.quizAssessment', 'finalQuiz']);

        // Keyed by chapter/material DB id for O(1) lookup
        $completedChapterIds = $enrollment->chapterProgress
            ->whereNotNull('completed_at')
            ->keyBy('chapter_id');

        $viewedMaterialIds = $enrollment->materialViews
            ->pluck('material_id', 'material_id');

        // Decorate chapters and materials with progress flags
        $course->chapters->each(function ($chapter) use ($completedChapterIds, $viewedMaterialIds) {
            $progress = $completedChapterIds->get($chapter->id);
            $chapter->completed         = (bool) $progress;
            $chapter->completed_at_marker = $progress?->completed_at?->toDateTimeString();

            $chapter->materials->each(function ($material) use ($viewedMaterialIds) {
                $material->viewed = isset($viewedMaterialIds[$material->id]);
            });
        });

        $course->enrollment = [
            'uuid'         => $enrollment->uuid,
            'status'       => $enrollment->status,
            'due_date'     => $enrollment->due_date?->toDateTimeString(),
            'completed_at' => $enrollment->completed_at?->toDateTimeString(),
            'final_score'  => $enrollment->final_score,
        ];

        return ApiResponse::success(CourseResource::make($course));
    }
}
