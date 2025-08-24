<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExperienceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_title' => $this->job_title,
            'employee_id' => $this->personnel_id,
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
