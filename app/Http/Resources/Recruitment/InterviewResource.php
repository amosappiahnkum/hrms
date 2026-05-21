<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Resources\Json\JsonResource;

class InterviewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'             => $this->uuid,
            'scheduled_at'     => $this->scheduled_at,
            'type'             => $this->type,
            'location'         => $this->location,
            'notes'            => $this->notes,
            'outcome'          => $this->outcome,
            'feedback'         => $this->feedback,
            'interviewer_uuid' => $this->interviewer?->uuid,
            'interviewer_name' => $this->interviewer?->name,
            'application_uuid' => $this->application?->uuid,
            'created_at'       => $this->created_at,
        ];
    }
}
