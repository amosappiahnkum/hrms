<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeCertificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'description'    => $this->description,
            'date_received'  => $this->date_received?->format('Y-m-d'),
            'expiry_date'    => $this->expiry_date?->format('Y-m-d'),
            'does_not_expire' => $this->does_not_expire,
            'file_name'      => $this->file_name,
            'file_size'      => $this->file_size,
            'mime_type'      => $this->mime_type,
            'provider'       => $this->whenLoaded('provider', fn() => [
                'id'   => $this->provider->id,
                'name' => $this->provider->name,
            ]),
            'employee'       => $this->whenLoaded('employee', fn() => [
                'id'       => $this->employee->id,
                'uuid'     => $this->employee->uuid,
                'name'     => $this->employee->name,
                'staff_id' => $this->employee->staff_id,
            ]),
            'uploaded_by'    => $this->whenLoaded('uploader', fn() => [
                'uuid' => $this->uploader->uuid,
                'name' => $this->uploader->name,
            ]),
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
