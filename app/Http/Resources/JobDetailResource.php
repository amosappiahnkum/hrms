<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobDetailResource extends JsonResource
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
            'position_uuid' => $this->position?->uuid,
            'position' => $this->position?->name,
            'status' => $this->status,
            'room' => $this->room,
            'location' => $this->location,
            'joined_date' => Carbon::parse($this->joined_date)->format('Y-m-d'),
            'employee_uuid' => $this->employee->uuid,
            'job_category_uuid' => $this->jobCategory->uuid,
            'job_category' => $this->jobCategory->name,
            'info_update' => $this->informationUpdate,
        ];
    }
}
