<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\InfoDifferenceService;
use App\Services\UpdateApprovalService;
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
            'contract_start_date' => $this->contract_start_date?->format('Y-m-d'),
            'contract_end_date' => $this->contract_end_date?->format('Y-m-d'),
            // Whether contract dates apply (fixed-term job types).
            'job_type' => $this->employee->job_type,
            'employee_uuid' => $this->employee->uuid,
            'job_category_uuid' => $this->jobCategory->uuid,
            'job_category' => $this->jobCategory->name,
            'info_update' => $this->informationUpdate,
            // A pending change, shaped for the form (uuids) and for display (names).
            'pending_values' => $this->informationUpdate
                ? app(UpdateApprovalService::class)->formValues($this->resource, $this->informationUpdate->new_info ?? [])
                : null,
            'pending_changes' => $this->informationUpdate
                ? app(InfoDifferenceService::class)->buildDiff(
                    $this->resource,
                    $this->informationUpdate->old_info ?? [],
                    $this->informationUpdate->new_info ?? []
                )
                : [],
        ];
    }
}
