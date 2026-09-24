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
            'period_start'    => $this->period_start?->toDateString(),
            'period_end'      => $this->period_end?->toDateString(),
            'assessment'      => $this->whenLoaded('assessment', fn () => [
                'uuid'        => $this->assessment->uuid,
                'name'        => $this->assessment->title,
                'type'        => $this->assessment->type,
                'include_training_section' => (bool) $this->assessment->include_training_section,
                'include_next_period_targets' => (bool) $this->assessment->include_next_period_targets,
                'job_categories' => $this->assessment->relationLoaded('jobCategories')
                    ? $this->assessment->jobCategories->map(fn ($c) => ['uuid' => $c->uuid, 'name' => $c->name])->values()
                    : [],
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
