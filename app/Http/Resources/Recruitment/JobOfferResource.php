<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Resources\Json\JsonResource;

class JobOfferResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'             => $this->uuid,
            'salary'           => $this->salary,
            'start_date'       => $this->start_date?->format('Y-m-d'),
            'expires_at'       => $this->expires_at?->format('Y-m-d'),
            'status'           => $this->status,
            'notes'            => $this->notes,
            'application_uuid' => $this->application?->uuid,
            'created_at'       => $this->created_at,
        ];
    }
}
