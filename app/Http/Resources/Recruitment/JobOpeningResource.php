<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Resources\Json\JsonResource;

class JobOpeningResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'             => $this->uuid,
            'title'            => $this->title,
            'description'      => $this->description,
            'requirements'     => $this->requirements,
            'salary_min'       => $this->salary_min,
            'salary_max'       => $this->salary_max,
            'location'         => $this->location,
            'status'           => $this->status,
            'deadline'         => $this->deadline?->format('Y-m-d'),
            'position_uuid'    => $this->position?->uuid,
            'position'         => $this->position?->name,
            'department_uuid'  => $this->department?->uuid,
            'department'       => $this->department?->name,
            'applications_count' => $this->whenLoaded('applications', fn() => $this->applications->count()),
            'created_at'       => $this->created_at,
        ];
    }
}
