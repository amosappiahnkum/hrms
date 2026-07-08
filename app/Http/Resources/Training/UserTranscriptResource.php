<?php

namespace App\Http\Resources\Training;

use App\Models\Training\CourseEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserTranscriptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enrollments = $this->courseEnrollments;
        $total       = $enrollments->count();
        $completed   = $enrollments->where('status', 'completed')->count();

        return [
            'uuid' => $this->uuid,
            'user' => [
                'name'       => $this->name,
                'email'      => $this->email,
                'job_title'  => $this->employee?->jobDetail?->position?->name,
                'department' => $this->employee?->department?->name,
            ],
            'total_courses'     => $total,
            'completed_courses' => $completed,
            'completion_rate'   => $total > 0 ? round($completed / $total * 100) : 0,
            'avg_score'         => $enrollments->whereNotNull('final_score')->avg('final_score')
                ? round((float) $enrollments->whereNotNull('final_score')->avg('final_score'), 1)
                : null,
            'courses' => $enrollments->map(fn (CourseEnrollment $e) => [
                'uuid'         => $e->uuid,
                'course_title' => $e->course?->title,
                'status'       => $e->status,
                'enrolled_at'  => $e->enrolled_at?->toDateTimeString(),
                'due_date'     => $e->due_date?->toDateTimeString(),
                'completed_at' => $e->completed_at?->toDateTimeString(),
                'final_score'  => $e->final_score !== null ? (float) $e->final_score : null,
            ])->values(),
        ];
    }
}
