<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;

class QualificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array|Arrayable|JsonSerializable
     */
    public function toArray($request): array|JsonSerializable|Arrayable
    {
        return [
            'uuid' => $this->uuid,
            'employee_uuid' => $this->employee_id,
            'education_level_uuid' => $this->educationLevel->uuid,
            'education_level' => $this->educationLevel->name,
            'field' => $this->field,
            'country' => $this->country,
            'institution' => $this->institution,
            'qualification' => $this->qualification,
            'cert_number' => $this->cert_number,
            'date' => $this->date,
            'type' => $this->type,
            'info_update' => $this->informationUpdate,
        ];
    }
}
