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
            'id' => $this->id,
            'position_id' => $this->position_id,
            'job_title' => $this->position?->name,
            'status' => $this->status,
            'location' => $this->location,
            'joined_date' => Carbon::parse($this->joined_date)->format('Y-m-d'),
            'contract_start_date' => Carbon::parse($this->contract_start_date)->format('Y-m-d'),
            'contract_end_date' => Carbon::parse($this->contract_end_date)->format('Y-m-d'),
            'employee_id' => $this->employee_id,
            'user_id' => $this->user_id,
            'job_category_id' => $this->job_category_id,
            'job_category' => $this->jobCategory->name,
            'sub_unit_id' => $this->sub_unit_id,
            'sub_unit' => $this->subUnit->name,
            'contract_detail' => $this->photo ? $this->photo->file_name : null,
            'info_update' => $this->informationUpdate,
        ];
    }
}
