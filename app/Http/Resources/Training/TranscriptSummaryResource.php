<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TranscriptSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = (int) $this->total_courses;

        return [
            'uuid' => $this->uuid,
            'user' => [
                'name'       => $this->name,
                'email'      => $this->email,
                'job_title'  => $this->employee?->jobDetail?->position?->name,
                'department' => $this->employee?->department?->name,
            ],
            'total_courses'       => $total,
            'completed_courses'   => (int) $this->completed_courses,
            'in_progress_courses' => (int) $this->in_progress_courses,
            'failed_courses'      => (int) $this->failed_courses,
            'completion_rate'     => $total > 0
                ? round((int) $this->completed_courses / $total * 100)
                : 0,
            'avg_score' => $this->avg_score ? round((float) $this->avg_score, 1) : null,
        ];
    }
}
