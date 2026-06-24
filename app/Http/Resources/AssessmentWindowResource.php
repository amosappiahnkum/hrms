<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentWindowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'            => $this->uuid,
            'title'           => $this->title,
            'description'     => $this->description,
            'status'          => $this->status,
            'start_date'      => $this->start_date?->toDateTimeString(),
            'end_date'        => $this->end_date?->toDateTimeString(),
            'assessment'      => $this->whenLoaded('assessment', fn () => [
                'uuid'        => $this->assessment->uuid,
                'name'        => $this->assessment->title,
                'type'        => $this->assessment->type,
                'job_category' => $this->assessment->assignable ? [
                    'uuid' => $this->assessment->assignable->uuid,
                    'name' => $this->assessment->assignable->name,
                ] : null,
            ]),
            'attempts_count'   => $this->when(isset($this->attempts_count), $this->attempts_count),
            'submitted_count'  => $this->when(isset($this->submitted_count), $this->submitted_count),
            'my_attempt'       => $this->whenLoaded('myAttempt', fn () =>
                $this->myAttempt->first()
                    ? AssessmentAttemptResource::make($this->myAttempt->first())
                    : null
            ),
            'created_at'      => $this->created_at,
        ];
    }
}
