<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;

class ContactDetailResource extends JsonResource
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
            'employee_uuid' => $this->employee->uuid,
            'address' => $this->address,
            'city' => $this->city,
            'region' => $this->region,
            'zip_code' => $this->zip_code,
            'country' => $this->country,
            'telephone' => $this->telephone,
            'work_telephone' => $this->work_telephone,
            'work_email' => $this->work_email,
            'other_email' => $this->other_email,
            'nationality' => $this->nationality,
            'info_update' => $this->informationUpdate,
        ];
    }
}
