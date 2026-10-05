<?php

namespace App\Services\TrainingPlan;

use App\Enums\TrainingPlan\EvaluationType;
use App\Models\TrainingPlan\TrainingEvaluation;
use App\Models\TrainingPlan\TrainingPlanItem;
use App\Models\User;
use App\Notifications\TrainingEvaluationNotification;
use Illuminate\Support\Carbon;

/**
 * Evaluations of completed trainings: the trainee's feedback (due within a week) and their
 * supervisor's review of whether it is applied on the job (due months later).
 */
class TrainingEvaluationService
{
    public function enabled(): bool
    {
        return feature('training_plan.enabled') && feature('training_plan.evaluations', true);
    }

    /** Ask for both evaluations of a newly completed training; the trainee is told at once. */
    public function schedule(TrainingPlanItem $item): void
    {
        if (!$this->enabled()) {
            return;
        }

        $item->loadMissing('employee.userAccount', 'employee.employeeSupervisor.supervisor.userAccount');
        $completed = Carbon::parse($item->completed_at ?? now());

        $feedback = $this->ensure($item, EvaluationType::PARTICIPANT_FEEDBACK, $item->employee?->userAccount,
            $completed->copy()->addDays((int) setting('training_plan.feedback_due_days', 7)));
        $this->ensure($item, EvaluationType::SUPERVISOR_REVIEW, $this->reviewerFor($item),
            $completed->copy()->addDays((int) setting('training_plan.supervisor_review_after_days', 90)));

        if ($feedback->wasRecentlyCreated) {
            $this->notify($feedback);
        }
    }

    /** The training is no longer completed: questions nobody answered yet are withdrawn. */
    public function withdraw(TrainingPlanItem $item): void
    {
        TrainingEvaluation::where('training_plan_item_id', $item->id)->pending()->delete();
    }

    /** The employee's supervisor, else the head of their department. */
    public function reviewerFor(TrainingPlanItem $item): ?User
    {
        $employee = $item->employee;

        return $employee?->employeeSupervisor?->supervisor?->userAccount ?? $employee?->hodUser();
    }

    /** Tell the evaluator (or, with nobody to ask, everyone preparing training plans). */
    public function notify(TrainingEvaluation $evaluation, bool $reminder = false): void
    {
        $recipients = $evaluation->evaluator
            ? collect([$evaluation->evaluator])
            : User::permission('prepare-training-plan')->get();

        foreach ($recipients as $user) {
            $user->notify(new TrainingEvaluationNotification($evaluation, $reminder));
        }

        $evaluation->forceFill($reminder ? ['reminded_at' => now()] : ['notified_at' => now()])->save();
    }

    private function ensure(TrainingPlanItem $item, EvaluationType $type, ?User $evaluator, Carbon $due): TrainingEvaluation
    {
        // One of each per completion; re-completing after a correction keeps an existing answer.
        return TrainingEvaluation::firstOrCreate(
            ['training_plan_item_id' => $item->id, 'type' => $type],
            ['evaluator_id' => $evaluator?->id, 'due_on' => $due->toDateString()],
        );
    }
}
