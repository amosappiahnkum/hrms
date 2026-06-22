<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PositionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->uuid,
            'name'            => $this->name,
            'total_employees' => $this->job_details_count ?? 0,
        ];
    }
}
