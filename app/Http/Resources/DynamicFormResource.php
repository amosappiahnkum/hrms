<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DynamicFormResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,

            'name' => $this->name,

            'model' => $this->model,

            'context' => $this->context,

            'description' => $this->description,

            'is_active' => $this->is_active,

            'is_extension' => $this->is_extension,

            'created_at' => $this->created_at,

            'updated_at' => $this->updated_at,

            'fields_count' => $this->whenCounted('fields'),
        ];
    }
}
