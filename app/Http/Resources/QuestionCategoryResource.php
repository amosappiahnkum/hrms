<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionCategoryResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'parent' => self::make($this->whenLoaded('parent')),
            'subCategories' => self::collection($this->whenLoaded('subCategories')),
            'created_at' => $this->created_at,
        ];
    }
}
