<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmergencyContactResource extends JsonResource
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
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'name' => $this->name,
            'relationship' => $this->relationship,
            'phone_number' => $this->phone_number,
            'alt_phone_number' => $this->alt_phone_number,
            'email' => $this->email,
            'user_id' => $this->user_id,
            'info_update' => $this->informationUpdate,
        ];
    }
}
