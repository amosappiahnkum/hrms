<?php

namespace App\Http\Resources\TrainingPlan;

use App\Http\Resources\TrainingPlan\Concerns\FormatsTrainingPlan;
use App\Models\User;
use App\Services\TrainingPlan\ApprovalService;
use App\Services\TrainingPlan\TrainingPlanAccess;
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
            'levels'          => app(ApprovalService::class)->progress($this->resource),
            'collection'      => [
                'starts_on' => $this->collection_starts_on?->toDateString(),
                'ends_on'   => $this->collection_ends_on?->toDateString(),
                'open'      => $this->isCollecting(),
            ],
            'can'             => $this->abilities($request->user()),
            'items_count'     => $this->whenCounted('items'),
            // More than zero while a revised plan is open: trainings from an earlier approval keep running.
            'approved_items_count' => $this->when(isset($this->approved_items_count), fn () => (int) $this->approved_items_count),
            'planned_cost'    => $this->when(isset($this->planned_cost), fn () => (float) $this->planned_cost),
            'created_at'      => $this->created_at,
        ];
    }

    /** What the viewer can do with this plan now. */
    private function abilities(?User $user): array
    {
        if (!$user) {
            return [];
        }

        $access = app(TrainingPlanAccess::class);
        $approvals = app(ApprovalService::class);
        $prepare = $access->canPrepare($user);
        $editable = $this->approval_status->isEditable();

        return [
            'edit'           => $prepare && $editable,
            'submit'         => $prepare && $editable,
            'collect'        => $prepare && $editable,
            'revise'         => $prepare && $this->isApproved(),
            'sign'           => $approvals->canSign($this->resource, $user),
            'add_trainings'  => ($prepare && $editable) || ($access->headsDepartments($user) && $this->isCollecting()),
            'see_everything' => $access->seesEverything($user),
        ];
    }
}
