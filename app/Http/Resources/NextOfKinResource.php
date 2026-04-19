<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NextOfKinResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'employee_uuid' => $this->employee->uuid,
            'phone_number' => $this->phone_number,
            'alt_phone_number' => $this->alt_phone_number,
            'address' => $this->address,
            'email' => $this->email,
            'info_update' => $this->informationUpdate,
        ];
    }
}
