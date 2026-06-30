<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseEnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'         => $this->uuid,
            'status'       => $this->status,
            'enrolled_at'  => $this->enrolled_at?->toDateTimeString(),
            'due_date'     => $this->due_date?->toDateTimeString(),
            'completed_at' => $this->completed_at?->toDateTimeString(),
            'final_score'  => $this->final_score,
            'course'       => $this->whenLoaded('course', fn () => [
                'uuid'  => $this->course->uuid,
                'title' => $this->course->title,
            ]),
            'user'         => $this->whenLoaded('user', fn () => [
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),
            // chapter_progress and material_views loaded by self-service show endpoint
            'chapter_progress' => $this->whenLoaded(
                'chapterProgress',
                fn () => $this->chapterProgress->map(fn ($p) => [
                    'chapter_id'   => $p->chapter_id,
                    'completed_at' => $p->completed_at?->toDateTimeString(),
                ])->values()
            ),
            'viewed_material_ids' => $this->whenLoaded(
                'materialViews',
                fn () => $this->materialViews->pluck('material_id')->values()
            ),
            'created_at'   => $this->created_at,
        ];
    }
}
