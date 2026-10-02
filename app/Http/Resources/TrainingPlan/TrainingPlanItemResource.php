<?php

namespace App\Http\Resources\TrainingPlan;

use App\Http\Resources\TrainingPlan\Concerns\FormatsTrainingPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingPlanItemResource extends JsonResource
{
    use FormatsTrainingPlan;

    public function toArray(Request $request): array
    {
        return [
            'uuid'               => $this->uuid,
            'plan'               => $this->whenLoaded('plan', fn () => [
                'uuid'  => $this->plan->uuid,
                'title' => $this->plan->title,
                'year'  => $this->plan->year,
            ]),
            'employee'           => $this->whenLoaded('employee', fn () => [
                'uuid'       => $this->employee->uuid,
                'name'       => $this->employee->name,
                'staff_id'   => $this->employee->staff_id,
                'department' => $this->employee->department?->name,
            ]),
            'catalogue_item_uuid' => $this->catalogueItem?->uuid,
            'title'              => $this->title,
            'nature'             => $this->option($this->nature),
            'domain'             => $this->domain?->name,
            'domain_uuid'        => $this->domain?->uuid,
            'category'           => $this->option($this->category),
            'source_of_need'     => $this->option($this->source_of_need),
            'supporting_record'  => $this->supporting_record,
            'quarter'            => $this->quarter,
            'days'               => $this->days,
            'cost'               => $this->cost,
            'trainer'            => $this->trainer,
            'delivery'           => $this->option($this->delivery),
            'planned_start_date' => $this->planned_start_date?->format('Y-m-d'),
            'planned_end_date'   => $this->planned_end_date?->format('Y-m-d'),
            'status'             => $this->option($this->status),
            'completed_at'       => $this->completed_at?->format('Y-m-d'),
            'comment'            => $this->comment,
            'approval'           => $this->approvalTrail(),
            // A head of department's line shows who asked for it.
            'added_by'           => $this->whenLoaded('creator', fn () => $this->creator ? [
                'uuid' => $this->creator->uuid,
                'name' => $this->creator->employee?->name ?? $this->creator->name,
            ] : null),
            'certifications'     => $this->whenLoaded('certifications', fn () => $this->certifications->map(fn ($c) => [
                'id'            => $c->id,
                'title'         => $c->title,
                'date_received' => $c->date_received?->format('Y-m-d'),
                'file_name'     => $c->file_name,
            ])->values()),
        ];
    }
}
