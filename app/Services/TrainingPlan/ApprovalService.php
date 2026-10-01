<?php

namespace App\Services\TrainingPlan;

use App\Enums\TrainingPlan\ApprovalStatus;
use App\Exceptions\UserFacingException;
use App\Models\Config\Setting;
use App\Models\TrainingPlan\TrainingPlan;
use App\Models\User;
use App\Notifications\TrainingPlanApprovalNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Prepare → validate → approve for training plans. Approving a plan approves every training in it.
 *
 * An approved plan is locked. To add or change trainings, a preparer revises it: the plan returns
 * to draft and goes through the whole cycle again, while trainings already approved keep running.
 *
 * Each step must be taken by a different person, unless the organisation has switched off
 * `features.training_plan.require_different_approvers`.
 */
class ApprovalService
{
    public function submit(TrainingPlan $plan, User $actor): void
    {
        if (!$plan->approval_status->isEditable()) {
            throw new UserFacingException('Only a draft or returned plan can be submitted.');
        }

        if (!$plan->items()->exists()) {
            throw new UserFacingException('Add at least one training to the plan before submitting it.');
        }

        $plan->update([
            'approval_status'   => ApprovalStatus::PENDING_VALIDATION,
            'prepared_by'       => $actor->id,
            'prepared_at'       => now(),
            'validated_by'      => null,
            'validated_at'      => null,
            'approved_by'       => null,
            'approved_at'       => null,
            'rejected_by'       => null,
            'rejected_at'       => null,
            'rejection_comment' => null,
        ]);

        $this->notify($plan, 'submitted', $actor);
    }

    public function validate(TrainingPlan $plan, User $actor): void
    {
        $this->ensureStatus($plan, ApprovalStatus::PENDING_VALIDATION, 'validation');
        $this->ensureDifferentPeople($actor, [$plan->prepared_by], 'validate what you prepared');

        $plan->update([
            'approval_status' => ApprovalStatus::PENDING_APPROVAL,
            'validated_by'    => $actor->id,
            'validated_at'    => now(),
        ]);

        $this->notify($plan, 'validated', $actor);
    }

    public function approve(TrainingPlan $plan, User $actor): void
    {
        $this->ensureStatus($plan, ApprovalStatus::PENDING_APPROVAL, 'approval');
        $this->ensureDifferentPeople($actor, [$plan->prepared_by, $plan->validated_by], 'approve what you prepared or validated');

        DB::transaction(function () use ($plan, $actor) {
            $stamps = [
                'approval_status' => ApprovalStatus::APPROVED,
                'approved_by'     => $actor->id,
                'approved_at'     => now(),
            ];

            $plan->update($stamps);

            // Trainings added or changed since the last approval are approved with the plan.
            $plan->items()->where('approval_status', '!=', ApprovalStatus::APPROVED->value)->update($stamps + [
                'prepared_by'  => $plan->prepared_by,
                'prepared_at'  => $plan->prepared_at,
                'validated_by' => $plan->validated_by,
                'validated_at' => $plan->validated_at,
            ]);
        });

        $this->notify($plan, 'approved', $actor);
    }

    public function reject(TrainingPlan $plan, User $actor, string $comment): void
    {
        $required = match ($plan->approval_status) {
            ApprovalStatus::PENDING_VALIDATION => 'validate-training-plan',
            ApprovalStatus::PENDING_APPROVAL   => 'approve-training-plan',
            default => throw new UserFacingException('Only a plan awaiting validation or approval can be rejected.'),
        };

        if (!$actor->can($required)) {
            throw new UserFacingException('You are not allowed to reject at this stage.', 403);
        }

        $this->ensureDifferentPeople($actor, [$plan->prepared_by], 'reject what you prepared');

        $plan->update([
            'approval_status'   => ApprovalStatus::REJECTED,
            'validated_by'      => null,
            'validated_at'      => null,
            'rejected_by'       => $actor->id,
            'rejected_at'       => now(),
            'rejection_comment' => $comment,
        ]);

        $this->notify($plan, 'rejected', $actor, $comment);
    }

    /**
     * Reopen an approved plan so trainings can be added or changed. It goes back to draft and
     * needs validating and approving again; trainings already approved stay approved and keep
     * running (progress, reminders, certificates) in the meantime.
     */
    public function revise(TrainingPlan $plan, User $actor): void
    {
        $this->ensureStatus($plan, ApprovalStatus::APPROVED, 'revision');

        $plan->update([
            'approval_status' => ApprovalStatus::DRAFT,
            'prepared_by'     => null,
            'prepared_at'     => null,
            'validated_by'    => null,
            'validated_at'    => null,
            'approved_by'     => null,
            'approved_at'     => null,
        ]);

        activity('training-plan')->causedBy($actor)->performedOn($plan)->event('revised')
            ->log("Reopened the {$plan->year} training plan for revision");
    }

    private function ensureStatus(TrainingPlan $plan, ApprovalStatus $expected, string $stage): void
    {
        if ($plan->approval_status !== $expected) {
            $message = $stage === 'revision'
                ? 'Only an approved plan can be revised.'
                : "This plan is not awaiting {$stage}.";

            throw new UserFacingException($message);
        }
    }

    private function ensureDifferentPeople(User $actor, array $userIds, string $action): void
    {
        if (!$this->requiresDifferentApprovers()) {
            return;
        }

        if (in_array($actor->id, array_map('intval', array_filter($userIds)), true)) {
            throw new UserFacingException("You cannot {$action}: each step must be done by a different person.", 403);
        }
    }

    /** Read on each call, so a change in System Features applies immediately. */
    private function requiresDifferentApprovers(): bool
    {
        $value = Setting::where('key', 'features.training_plan.require_different_approvers')->value('value');

        return $value === null ? true : (bool) $value;
    }

    private function notify(TrainingPlan $plan, string $event, User $actor, ?string $comment = null): void
    {
        $recipients = match ($event) {
            'submitted' => User::permission('validate-training-plan')->get(),
            'validated' => User::permission('approve-training-plan')->get(),
            default     => collect([$plan->preparer]),
        };

        // Nobody is asked to review their own work.
        $excluded = in_array($event, ['approved', 'rejected'], true)
            ? [$actor->id]
            : array_filter([$actor->id, $plan->prepared_by, $plan->validated_by]);

        $recipients = $recipients->filter()
            ->reject(fn (User $u) => in_array($u->id, $excluded))
            ->unique('id');

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new TrainingPlanApprovalNotification($plan, $event, $actor, $comment));
        }
    }
}
