<?php

namespace App\Http\Resources\TrainingPlan;

use App\Http\Resources\TrainingPlan\Concerns\FormatsTrainingPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingPlanResource extends JsonResource
{
    use FormatsTrainingPlan;

    public function toArray(Request $request): array
    {
        return [
            'uuid'            => $this->uuid,
            'year'            => $this->year,
            'title'           => $this->title,
            'budget_factor'   => $this->budget_factor,
            'approval'        => $this->approvalTrail(),
            'items_count'     => $this->whenCounted('items'),
            // More than zero while a revised plan is open: trainings from an earlier approval keep running.
            'approved_items_count' => $this->when(isset($this->approved_items_count), fn () => (int) $this->approved_items_count),
            'planned_cost'    => $this->when(isset($this->planned_cost), fn () => (float) $this->planned_cost),
            'created_at'      => $this->created_at,
        ];
    }
}
