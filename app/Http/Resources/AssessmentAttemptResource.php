<?php

namespace App\Http\Resources;

use App\Models\Appraisal\AssessmentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\AssessmentAttemptEventResource;
use App\Http\Resources\AppraisalKpiResource;

class AssessmentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'                    => $this->uuid,
            'status'                  => $this->status,
            'is_editable'             => $this->isEditable(),
            'started_at'              => $this->started_at?->toDateTimeString(),
            'submitted_at'            => $this->submitted_at?->toDateTimeString(),

            // Employee comment (appraisal only)
            'employee_comment'        => $this->employee_comment,

            // Supervisor review (appraisal only)
            'supervisor'              => $this->when($this->supervisor_id, fn () => [
                'uuid' => $this->supervisor?->uuid,
                'name' => $this->supervisor?->name,
            ]),
            'supervisor_comment'      => $this->supervisor_comment,
            'supervisor_confirmed_at' => $this->supervisor_confirmed_at?->toDateTimeString(),

            'window'          => $this->whenLoaded('window', fn () =>
                AssessmentWindowResource::make($this->window)
            ),
            'user'            => $this->whenLoaded('user', fn () => [
                'uuid'  => $this->user->uuid,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),
            'responses'       => $this->whenLoaded('responses', fn () =>
                $this->responses->map(fn ($r) => [
                    'response_uuid'          => $r->uuid,
                    'question_uuid'          => $r->question?->uuid,
                    'question_text'          => $r->question?->text,
                    'question_type'          => $r->question?->type,
                    'question_options'       => $r->question?->options->map(fn ($o) => [
                        'uuid'         => $o->uuid,
                        'option_text'  => $o->option_text,
                        'option_value' => $o->option_value,
                        'order'        => $o->order,
                    ]) ?? [],
                    'answer'                 => $r->answer,
                    'score'                  => $r->score,
                    'supervisor_answer'      => $r->supervisor_answer,
                    'supervisor_score'       => $r->supervisor_score,
                    'supervisor_updated_at'  => $r->supervisor_updated_at?->toDateTimeString(),
                ])
            ),
            'responses_count' => $this->when(isset($this->responses_count), $this->responses_count),
            'events'          => $this->whenLoaded('events', fn () =>
                AssessmentAttemptEventResource::collection($this->events)
            ),
            'kpis'            => $this->whenLoaded('kpis', fn () =>
                AppraisalKpiResource::collection($this->kpis)
            ),
        ];
    }
}
