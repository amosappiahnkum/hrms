<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeLevelConfigResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'employee_level' => $this->jobCategory->name,
            'entitlement_type' => $this->entitlement_type,
            'number_of_days' => $this->number_of_days,
            'start_of_annual_cycle' => $this->start_of_annual_cycle,
            'allow_half_day' => $this->allow_half_day,
            'allow_carry_forward' => $this->allow_carry_forward,
            'maximum_allotment' => $this->maximum_allotment,
            'maximum_consecutive_days' => $this->maximum_consecutive_days,
            'should_request_before' => $this->should_request_before,
        ];
    }
}
