<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeKpiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'         => $this->uuid,
            'period_start' => $this->period_start?->toDateString(),
            'period_end'   => $this->period_end?->toDateString(),
            'description'  => $this->description,
            'target'       => (float) $this->target,
            'order'        => $this->order,
            'set_by'       => $this->whenLoaded('setter', fn () => $this->setter ? ['uuid' => $this->setter->uuid, 'name' => $this->setter->name] : null),
        ];
    }
}
