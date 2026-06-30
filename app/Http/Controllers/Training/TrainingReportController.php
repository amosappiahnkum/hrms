<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Training\Course;
use App\Models\Training\CourseEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingReportController extends Controller
{
    /**
     * Overall training stats — total enrollments, completion rate, avg score.
     */
    public function overview(): JsonResponse
    {
        $stats = CourseEnrollment::selectRaw('
            COUNT(*)                                                        AS total,
            SUM(status = "completed")                                       AS completed,
            SUM(status = "in_progress")                                     AS in_progress,
            SUM(status = "enrolled")                                        AS not_started,
            SUM(status = "failed")                                          AS failed,
            ROUND(AVG(CASE WHEN final_score IS NOT NULL THEN final_score END), 1) AS avg_score
        ')->first();

        $total = max((int) $stats->total, 1);

        return ApiResponse::success([
            'total_courses'     => Course::count(),
            'total_enrollments' => (int) $stats->total,
            'completed'         => (int) $stats->completed,
            'in_progress'       => (int) $stats->in_progress,
            'not_started'       => (int) $stats->not_started,
            'failed'            => (int) $stats->failed,
            'completion_rate'   => round((int) $stats->completed / $total * 100, 1),
            'avg_score'         => $stats->avg_score ? (float) $stats->avg_score : null,
        ]);
    }

    /**
     * Per-course completion stats — paginated.
     */
    public function courseStats(Request $request): JsonResponse
    {
        $courses = Course::withCount([
                'enrollments',
                'enrollments as completed_count'   => fn ($q) => $q->where('status', 'completed'),
                'enrollments as in_progress_count' => fn ($q) => $q->where('status', 'in_progress'),
                'enrollments as failed_count'      => fn ($q) => $q->where('status', 'failed'),
            ])
            ->withAvg(
                ['enrollments as avg_score' => fn ($q) => $q->whereNotNull('final_score')],
                'final_score'
            )
            ->when($request->search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($request->filled('is_published'), fn ($q) => $q->where('is_published', $request->boolean('is_published')))
            ->orderByDesc('enrollments_count')
            ->paginate($request->integer('per_page', 20));

        return response()->json($courses->through(function (Course $c) {
            $total     = (int) $c->enrollments_count;
            $completed = (int) $c->completed_count;

            return [
                'uuid'              => $c->uuid,
                'title'             => $c->title,
                'is_published'      => $c->is_published,
                'total_enrollments' => $total,
                'completed'         => $completed,
                'in_progress'       => (int) $c->in_progress_count,
                'failed'            => (int) $c->failed_count,
                'not_started'       => max($total - $completed - (int) $c->in_progress_count - (int) $c->failed_count, 0),
                'completion_rate'   => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
                'avg_score'         => $c->avg_score ? round((float) $c->avg_score, 1) : null,
            ];
        }));
    }

    /**
     * Enrollment-level transcript — one row per enrollment, filterable.
     */
    public function enrollmentTranscripts(Request $request): JsonResponse
    {
        $enrollments = CourseEnrollment::with([
                'user:id,name,email',
                'user.employee:id,user_id,department_id,job_title,first_name,last_name',
                'user.employee.department:id,name',
                'course:id,uuid,title',
            ])
            ->when($request->course, fn ($q, $uuid) =>
                $q->whereHas('course', fn ($cq) => $cq->where('uuid', $uuid))
            )
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->department_id, fn ($q, $d) =>
                $q->whereHas('user.employee', fn ($eq) => $eq->where('department_id', $d))
            )
            ->when($request->search, fn ($q, $s) =>
                $q->whereHas('user', fn ($uq) =>
                    $uq->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
                )
            )
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->json($enrollments->through(fn (CourseEnrollment $e) => [
            'uuid'         => $e->uuid,
            'user'         => [
                'name'       => $e->user?->name,
                'email'      => $e->user?->email,
                'job_title'  => $e->user?->employee?->job_title,
                'department' => $e->user?->employee?->department?->name,
            ],
            'course'       => [
                'uuid'  => $e->course?->uuid,
                'title' => $e->course?->title,
            ],
            'status'       => $e->status,
            'enrolled_at'  => $e->enrolled_at?->toDateTimeString(),
            'due_date'     => $e->due_date?->toDateTimeString(),
            'completed_at' => $e->completed_at?->toDateTimeString(),
            'final_score'  => $e->final_score ? (float) $e->final_score : null,
        ]));
    }
}
