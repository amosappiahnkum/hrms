<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DynamicFieldResource extends JsonResource
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
            'label' => $this->label,
            'dynamic_form_id' => $this->dynamic_form_id,
            'type' => $this->type,
            'is_required' => $this->is_required,
            'is_active' => $this->is_active,
            'colSpan' => $this->col_span,
            'rules' => $this->rules ?? [],
            'props' => $this->props ?? [],
            'behavior' => $this->behavior ?? [],
            'dataSource' => $this->data_source ?? [],
            'transformers' => $this->transformers ?? [],
            'options' => DynamicFieldOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
