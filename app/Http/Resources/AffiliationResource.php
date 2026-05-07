<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'association' => $this->association,
            'role' => $this->role,
            'description' => $this->description,
            'start' => $this->start,
            'end' => $this->end,
            'employee_uuid' => $this->employee->uuid,
        ];
    }
}
