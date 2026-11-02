<?php

namespace App\Http\Resources;

use App\Services\UpdateApprovalService;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;

class PreviousPositionResource extends JsonResource
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
            'employee_uuid' => $this->employee->uuid,
            'position_uuid' => $this->position->uuid,
            'department_uuid' => $this->department?->uuid,
            'department' => $this->department?->name,
            'name' => $this->position->name,
            'start' => $this->start,
            'end' => $this->end,
            // A pending change, shaped for the form (related records by uuid).
            'pending_values' => $this->pendingApproval
                ? app(UpdateApprovalService::class)->formValues($this->resource, $this->pendingApproval->new_info ?? [])
                : null,
        ];
    }
}
