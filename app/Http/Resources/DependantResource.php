<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DependantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'employee_uuid' => $this->employee->uuid,
            'relationship' => $this->relationship,
            'phone_number' => $this->phone_number,
            'alt_phone_number' => $this->alt_phone_number,
            'dob' => $this->dob,
            'info_update' => $this->informationUpdate,
        ];
    }
}
