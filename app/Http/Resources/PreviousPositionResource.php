<?php

namespace App\Http\Resources;

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
            'end' => $this->end
        ];
    }
}
