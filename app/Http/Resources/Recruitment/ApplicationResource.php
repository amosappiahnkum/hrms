<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'             => $this->uuid,
            'status'           => $this->status,
            'cover_letter'     => $this->cover_letter,
            'applied_at'       => $this->applied_at,
            'candidate'        => new CandidateResource($this->whenLoaded('candidate')),
            'job_opening'      => new JobOpeningResource($this->whenLoaded('jobOpening')),
            'interviews_count' => $this->whenLoaded('interviews', fn() => $this->interviews->count()),
            'has_offer'        => $this->whenLoaded('offer', fn() => $this->offer !== null),
            'employee_uuid'    => $this->employee?->uuid,
            'created_at'       => $this->created_at,
        ];
    }
}
