<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ExperienceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'job_title' => $this->job_title,
            'employee_uuid' => $this->employee->uuid,
            'company' => $this->company,
            'city' => $this->city,
            'country' => $this->country,
            'from' => $this->from,
            'to' => $this->to,
            'comment' => $this->comment,
            'job_type' => $this->job_type,
            'info_update' => $this->informationUpdate,
        ];
    }
}
