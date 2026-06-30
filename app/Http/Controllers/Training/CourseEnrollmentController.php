<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Training\CourseEnrollmentResource;
use App\Models\Training\Course;
use App\Models\Training\CourseEnrollment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CourseEnrollmentController extends Controller
{
    /**
     * List all enrollments (admin-wide or filtered by course).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $enrollments = CourseEnrollment::with(['course', 'user'])
            ->when($request->course_uuid, fn ($q, $uuid) =>
                $q->whereHas('course', fn ($c) => $c->where('uuid', $uuid))
            )
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return CourseEnrollmentResource::collection($enrollments);
    }

    /**
     * Admin manually enrolls one or more users in a course.
     */
    public function store(Request $request, Course $course): JsonResponse
    {
        $request->validate([
            'user_ids'   => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'exists:users,id'],
            'due_date'   => ['nullable', 'date'],
        ]);

        $existingUserIds = $course->enrollments()->pluck('user_id')->flip();
        $toEnroll        = collect($request->user_ids)->reject(fn ($id) => $existingUserIds->has($id));

        $now  = now();
        $rows = $toEnroll->map(fn ($uid) => [
            'course_id'   => $course->id,
            'user_id'     => $uid,
            'status'      => CourseEnrollment::STATUS_ENROLLED,
            'enrolled_at' => $now,
            'due_date'    => $request->due_date,
            'created_at'  => $now,
            'updated_at'  => $now,
        ])->values()->all();

        CourseEnrollment::insert($rows);

        $skipped = count($request->user_ids) - count($toEnroll);

        activity('training')->log(
            "Manually enrolled " . count($toEnroll) . " user(s) in course: {$course->title}"
            . ($skipped ? " ({$skipped} already enrolled, skipped)" : '')
        );

        return ApiResponse::success([
            'enrolled' => count($toEnroll),
            'skipped'  => $skipped,
        ], count($toEnroll) . ' user(s) enrolled.', 201);
    }

    /**
     * Admin unenrolls a user (removes their enrollment + progress).
     */
    public function destroy(CourseEnrollment $courseEnrollment): JsonResponse
    {
        $name   = $courseEnrollment->user?->name ?? "user #{$courseEnrollment->user_id}";
        $course = $courseEnrollment->course?->title ?? "course #{$courseEnrollment->course_id}";

        // Cascades delete chapter_progress, material_views, and linked quiz attempts
        $courseEnrollment->delete();

        activity('training')->log("Unenrolled {$name} from {$course}");

        return ApiResponse::success([], 'Enrollment removed.');
    }
}
