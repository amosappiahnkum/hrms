<?php

namespace App\Http\Resources;

use App\Services\InfoDifferenceService;
use App\Services\UpdateApprovalService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $modelClass = Relation::getMorphedModel($this->information_type) ?? $this->information_type;

        $model = new $modelClass;

        $diff = app(InfoDifferenceService::class)->buildDiff(
            $model,
            $this->old_info ?? [],
            $this->new_info ?? []
        );

        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'model' => class_basename($this->information_type),
            'status' => $this->status,
            'requested_by' => $this->requestedBy?->employee?->name,
            'reviewed_by' => $this->reviewedBy?->employee?->name,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at,
            'reviewed_at' => $this->reviewed_at,
            'diff' => $diff,
        ];
    }
}
