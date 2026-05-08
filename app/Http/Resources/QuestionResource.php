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

            'type' => $this->type,

            'text' => $this->text,

            'description' => $this->description,

            'weight' => (float) $this->weight,

            'is_required' => $this->is_required,

            'is_active' => $this->is_active,

            'order' => $this->order,

            'category' => QuestionCategoryResource::make(
                $this->whenLoaded('category')
            ),

            'options' => QuestionOptionResource::collection(
                $this->whenLoaded('options')
            ),

            'created_at' => $this->created_at,
        ];

    }
}
