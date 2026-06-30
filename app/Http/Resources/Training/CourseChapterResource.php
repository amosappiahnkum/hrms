<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseChapterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'             => $this->uuid,
            'title'            => $this->title,
            'description'      => $this->description,
            'order'            => $this->order,
            'materials'        => $this->whenLoaded(
                'materials',
                fn () => CourseMaterialResource::collection($this->materials)
            ),
            'materials_count'  => $this->when(
                isset($this->materials_count),
                fn () => $this->materials_count
            ),
            'completed'        => $this->when(isset($this->completed), fn () => $this->completed),
            'completed_at'     => $this->when(isset($this->completed_at_marker), fn () => $this->completed_at_marker),
            'created_at'       => $this->created_at,
        ];
    }
}
