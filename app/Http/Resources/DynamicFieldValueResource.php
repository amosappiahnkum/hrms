<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DynamicFieldValueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'field' => [
                'uuid' => $this->field?->uuid,
                'name' => $this->field?->name,
                'label' => $this->field?->label,
            ],
            'value' => $this->value,
        ];
    }
}
