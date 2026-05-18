<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DynamicFormSchemaResource extends JsonResource
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

            'model' => $this->model,

            'context' => $this->context,

            'fields' => DynamicFieldResource::collection(
                $this->fields
            ),
        ];
    }
}
