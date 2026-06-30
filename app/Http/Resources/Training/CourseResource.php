<?php

namespace App\Http\Resources\Training;

use App\Helpers\Helper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->whenLoaded('category', fn() => [
                'uuid' => $this->category->uuid,
                'name' => $this->category->name,
            ]),
            'course_category_id' => $this->course_category_id,
            'thumbnail_url' => Helper::getTempUrl($this->thumbnail_url),
            'duration_minutes' => $this->duration_minutes,
            'final_quiz_uuid' => $this->whenLoaded('finalQuiz', fn() => $this->finalQuiz?->uuid, $this->finalQuiz?->uuid),
            'passing_score' => $this->passing_score,
            'is_published' => $this->is_published,
            'chapters_count' => $this->when(isset($this->chapters_count), fn() => $this->chapters_count),
            'enrollments_count' => $this->when(isset($this->enrollments_count), fn() => $this->enrollments_count),
            'chapters' => $this->whenLoaded(
                'chapters',
                fn() => CourseChapterResource::collection($this->chapters)
            ),
            'assignments' => $this->whenLoaded(
                'assignments',
                fn() => $this->assignments->map(fn($a) => [
                    'id' => $a->id,
                    'scope_type' => $a->scope_type,
                    'scope_ids' => $a->scope_ids,
                    'due_date' => $a->due_date?->toDateTimeString(),
                ])->values()
            ),
            // Self-service: current user's enrollment injected by MyCourseController
            'enrollment' => $this->when(isset($this->enrollment), fn() => $this->enrollment),
            'created_at' => $this->created_at,
        ];
    }
}
