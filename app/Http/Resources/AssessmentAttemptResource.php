<?php

namespace App\Http\Resources;

use App\Models\Appraisal\AssessmentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
                    'question_uuid'  => $r->question?->uuid,
                    'question_text'  => $r->question?->text,
                    'question_type'  => $r->question?->type,
                    'answer'         => $r->answer,
                    'score'          => $r->score,
                ])
            ),
            'responses_count' => $this->when(isset($this->responses_count), $this->responses_count),
        ];
    }
}
