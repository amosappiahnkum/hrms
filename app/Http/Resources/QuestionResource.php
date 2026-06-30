<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,

            'scope' => $this->scope,

            'course_uuid' => $this->whenLoaded('course', fn () => $this->course?->uuid),

            'type' => $this->type,

            'text' => $this->text,

            'description' => $this->description,

            'weight' => (float) $this->weight,

            'is_required' => $this->is_required,

            'is_active' => $this->is_active,

            'order' => $this->order,

            'category' => QuestionCategoryResource::make(
                $this->whenLoaded('questionCategory')
            ),

            'options' => QuestionOptionResource::collection(
                $this->whenLoaded('options')
            ),

            // Conditional display — null means always show
            'depends_on_uuid'  => $this->dependsOn?->uuid,
            'show_when_value'  => $this->show_when_value,

            'created_at' => $this->created_at,
        ];

    }
}
