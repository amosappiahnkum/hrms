<?php

namespace App\Services\Competency;

use App\Enums\Competency\DevelopmentStatus;
use App\Enums\Competency\EffectivenessResult;
use App\Enums\TrainingPlan\TrainingStatus;
use App\Exceptions\UserFacingException;
use App\Models\Competency\CompetencyAssessment;
use App\Models\Competency\CompetencyRating;
use App\Models\Competency\DevelopmentAction;
use App\Models\TrainingPlan\TrainingPlanItem;
use App\Models\User;
use App\Notifications\DevelopmentActionEvaluationNotification;
use Illuminate\Support\Facades\DB;

/**
 * Closing the loop on development (SOP 5.3.6): when the development has taken place its effectiveness
 * is checked by re-rating the competency, and only that re-rating can close the gap.
 */
class EffectivenessService
{
    public function __construct(private readonly CompetencyService $competency)
    {
    }

    /** A linked training ended: completed → ready to evaluate; failed or cancelled → back to planning. */
    public function trainingEnded(TrainingPlanItem $item): void
    {
        $actions = DevelopmentAction::open()->where('training_plan_item_id', $item->id)->with(['employee', 'competency', 'creator'])->get();

        foreach ($actions as $action) {
            if ($item->status === TrainingStatus::COMPLETED) {
                if ($action->status === DevelopmentStatus::AWAITING_EVALUATION) {
                    continue;
                }
                $action->update(['status' => DevelopmentStatus::AWAITING_EVALUATION]);
                if ($assessor = $this->assessorFor($action)) {
                    $assessor->notify(new DevelopmentActionEvaluationNotification($action));
                }
            } elseif (in_array($item->status, [TrainingStatus::FAILED, TrainingStatus::CANCELLED], true)) {
                // Unlinked, so it goes back to the training team's needs queue.
                $note = "{$item->title} was {$item->status->label()} on " . now()->format('j M Y') . '.';
                $action->update([
                    'status'                => DevelopmentStatus::PLANNED,
                    'training_plan_item_id' => null,
                    'outcome'               => trim(($action->outcome ? "{$action->outcome}\n" : '') . $note),
                ]);
            }
        }
    }

    /** Who checks the effectiveness: whoever planned the action, if they still may; else the employee's leader. */
    public function assessorFor(DevelopmentAction $action): ?User
    {
        if ($action->creator && $this->competency->canAssess($action->creator, $action->employee)) {
            return $action->creator;
        }

        $employee = $action->employee;

        return $employee?->employeeSupervisor?->supervisor?->userAccount ?? $employee?->hodUser();
    }

    /**
     * Re-rate the competency and record how effective the action was. The re-rating is saved as a
     * completed assessment carrying the employee's other current levels forward, so it can become
     * their current record without losing anything. The action is finished either way; when the
     * level still falls short the gap stays open, without a plan, for the next action.
     */
    public function evaluate(DevelopmentAction $action, User $user, array $data): DevelopmentAction
    {
        if (!$action->status->isOpen()) {
            throw new UserFacingException('This action is already finished.');
        }

        $employee = $action->employee->loadMissing('jobDetail');
        $required = $this->competency->requirementsFor($employee->jobDetail?->position_id)->get($action->competency_id);
        $result = EffectivenessResult::from($data['result']);
        $met = $required === null || $data['verified_level'] >= $required;

        if ($result === EffectivenessResult::EFFECTIVE && !$met) {
            throw new UserFacingException("Level {$data['verified_level']} is below the required level {$required}, so the action can't be recorded as effective.");
        }

        $evaluated = DB::transaction(function () use ($action, $user, $data, $employee, $required, $result) {
            $latest = $this->competency->latestAssessments([$employee->id])->get($employee->id);

            $assessment = CompetencyAssessment::create([
                'employee_id'    => $employee->id,
                'position_id'    => $employee->jobDetail?->position_id,
                'assessor_id'    => $user->id,
                'status'         => CompetencyAssessment::COMPLETED,
                'assessed_on'    => now()->toDateString(),
                // A spot check doesn't restart the review cycle.
                'next_review_on' => $latest?->next_review_on?->toDateString() ?? $this->competency->defaultNextReview(now())->toDateString(),
                'completed_at'   => now(),
                'comment'        => "Effectiveness check of {$action->method->label()} for {$action->competency->name}.",
            ]);

            foreach ($latest?->ratings ?? [] as $previous) {
                if ($previous->competency_id !== $action->competency_id) {
                    $carried = $assessment->ratings()->create($previous->only(['competency_id', 'required_level', 'level', 'evidence']));
                    $previous->evidenceFiles->each->copyTo($carried);
                }
            }
            $rating = $assessment->ratings()->create([
                'competency_id'  => $action->competency_id,
                'required_level' => $required,
                'level'          => $data['verified_level'],
                'evidence'       => $data['evidence'] ?? null,
            ]);

            $action->update([
                'status'               => DevelopmentStatus::DONE,
                'completed_on'         => $action->completed_on ?? now()->toDateString(),
                'effectiveness_result' => $result,
                'verified_level'       => $data['verified_level'],
                'competency_rating_id' => $rating->id,
                'evaluated_by'         => $user->id,
                'evaluated_on'         => now()->toDateString(),
                'outcome'              => $data['outcome'] ?? $action->outcome,
            ]);

            return $action->fresh();
        });

        app(AuthorizationService::class)->recheck($employee);

        return $evaluated;
    }
}
