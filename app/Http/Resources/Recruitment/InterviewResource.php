<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Resources\Json\JsonResource;

class InterviewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'           => $this->uuid,
            'scheduled_at'   => $this->scheduled_at,
            'type'           => $this->type,
            'location'       => $this->location,
            'notes'          => $this->notes,
            'outcome'        => $this->outcome,
            'feedback'       => $this->feedback,
            'interviewers'   => $this->whenLoaded('interviewers', fn() =>
                $this->interviewers->map(fn($e) => [
                    'uuid' => $e->uuid,
                    'name' => $e->name,
                ])
            ),
            'application_uuid' => $this->application?->uuid,
            'candidate_name'   => $this->application?->candidate?->name,
            'candidate_uuid'   => $this->application?->candidate?->uuid,
            'created_at'       => $this->created_at,
        ];
    }
}
