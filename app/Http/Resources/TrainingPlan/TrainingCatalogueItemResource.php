<?php

namespace App\Http\Resources\TrainingPlan;

use App\Http\Resources\TrainingPlan\Concerns\FormatsTrainingPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingCatalogueItemResource extends JsonResource
{
    use FormatsTrainingPlan;

    public function toArray(Request $request): array
    {
        return [
            'uuid'           => $this->uuid,
            'title'          => $this->title,
            'nature'         => $this->option($this->nature),
            'domain'         => $this->domain?->name,
            'domain_uuid'    => $this->domain?->uuid,
            'default_days'   => $this->default_days,
            'estimated_cost' => $this->estimated_cost,
            'trainer'        => $this->trainer,
            'location'       => $this->location,
            'plan_items_count' => $this->whenCounted('planItems'),
        ];
    }
}
