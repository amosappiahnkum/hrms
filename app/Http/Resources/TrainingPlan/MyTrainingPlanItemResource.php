<?php

namespace App\Http\Resources\TrainingPlan;

use App\Http\Resources\TrainingPlan\Concerns\FormatsTrainingPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A training planned for the signed-in employee: what they need to know, without budget or approval details. */
class MyTrainingPlanItemResource extends JsonResource
{
    use FormatsTrainingPlan;

    public function toArray(Request $request): array
    {
        return [
            'uuid'               => $this->uuid,
            'year'               => $this->plan->year,
            'title'              => $this->title,
            'nature'             => $this->option($this->nature),
            'domain'             => $this->domain?->name,
            'domain_uuid'        => $this->domain?->uuid,
            'quarter'            => $this->quarter,
            'days'               => $this->days,
            'trainer'            => $this->trainer,
            'delivery'           => $this->option($this->delivery),
            'planned_start_date' => $this->planned_start_date?->format('Y-m-d'),
            'planned_end_date'   => $this->planned_end_date?->format('Y-m-d'),
            'status'             => $this->option($this->status),
            'completed_at'       => $this->completed_at?->format('Y-m-d'),
            'comment'            => $this->comment,
            'has_certificate'    => $this->certifications->isNotEmpty(),
        ];
    }
}
