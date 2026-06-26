<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentAttemptEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'       => $this->uuid,
            'event_type' => $this->event_type,
            'comment'    => $this->comment,
            'actor'      => $this->when($this->actor_id, fn () => [
                'uuid' => $this->actor?->uuid,
                'name' => $this->actor?->name,
            ]),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
