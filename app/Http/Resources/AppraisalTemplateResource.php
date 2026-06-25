<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppraisalTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'        => $this->uuid,
            'name'        => $this->title,
            'description' => $this->description,
            'is_active'   => $this->is_active,
            'job_categories' => $this->whenLoaded('jobCategories', fn () =>
                $this->jobCategories->map(fn ($c) => ['uuid' => $c->uuid, 'name' => $c->name])->values()
            ),
            'questions_count' => $this->when(
                isset($this->questions_count),
                $this->questions_count
            ),
            'questions' => $this->whenLoaded('questionUsages', fn () =>
                $this->questionUsages->map(fn ($usage) => [
                    'usage_uuid'    => $usage->uuid,
                    'order'         => $usage->order,
                    'custom_weight' => $usage->custom_weight,
                    'question'      => QuestionResource::make($usage->question),
                ])
            ),
            'created_at' => $this->created_at,
        ];
    }
}
