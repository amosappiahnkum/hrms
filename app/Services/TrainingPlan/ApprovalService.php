<?php

namespace App\Services\TrainingPlan;

use App\Enums\TrainingPlan\ApprovalStatus;
use App\Exceptions\UserFacingException;
use App\Models\Config\Setting;
use App\Models\TrainingPlan\TrainingPlan;
use App\Models\TrainingPlan\TrainingPlanApprovalLevel;
use App\Models\TrainingPlan\TrainingPlanSignoff;
use App\Models\User;
use App\Notifications\TrainingPlanApprovalNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Prepare → sign off level by level → approved. Approving a plan approves every training in it.
 *
 * The levels come from the approval levels page; a plan keeps the chain it was submitted with.
 * In each level either everyone signs (rule "all") or one person is enough ("any"); the last level
 * is the final approval. Anyone in the current level may return the plan to HR with a comment.
 *
 * An approved plan is locked. To add or change trainings, a preparer revises it: the plan returns
 * to draft and goes through the whole cycle again, while trainings already approved keep running.
 *
 * Nobody signs what they prepared, nor at two levels of the same round, unless the organisation
 * has switched off `features.training_plan.require_different_approvers`.
 */
class ApprovalService
{
    public function __construct(private readonly TrainingPlanAccess $access) {}

    public function submit(TrainingPlan $plan, User $actor): void
    {
        if (!$plan->approval_status->isEditable()) {
            throw new UserFacingException('Only a draft or returned plan can be submitted.');
        }

        if (!$plan->items()->exists()) {
            throw new UserFacingException('Add at least one training to the plan before submitting it.');
        }

        $chain = TrainingPlanApprovalLevel::snapshot();
        $this->ensureChainUsable($chain, $actor);

        $plan->update([
            'approval_status'   => count($chain) === 1 ? ApprovalStatus::PENDING_APPROVAL : ApprovalStatus::PENDING_VALIDATION,
            'approval_chain'    => $chain,
            'current_level'     => 0,
            'approval_round'    => $plan->approval_round + 1,
            'prepared_by'       => $actor->id,
            'prepared_at'       => now(),
            'approved_by'       => null,
            'approved_at'       => null,
            'rejected_by'       => null,
            'rejected_at'       => null,
            'rejection_comment' => null,
        ]);

        $this->notifyLevel($plan, $actor);
    }

    /** Sign the level the plan is waiting on; when the level is complete the plan moves on. */
    public function signOff(TrainingPlan $plan, User $actor): void
    {
        $this->ensureCanSign($plan, $actor, 'sign');

        DB::transaction(function () use ($plan, $actor) {
            $this->record($plan, $actor, TrainingPlanSignoff::APPROVED);

            if (!$this->levelComplete($plan)) {
                return;
            }

            if ($plan->isFinalLevel()) {
                $this->approve($plan, $actor);
                return;
            }

            $next = $plan->current_level + 1;
            $plan->update([
                'current_level'   => $next,
                'approval_status' => $next === count($plan->approval_chain) - 1 ? ApprovalStatus::PENDING_APPROVAL : ApprovalStatus::PENDING_VALIDATION,
            ]);

            $this->notifyLevel($plan, $actor);
        });
    }

    /** Anyone in the current level may return the plan to HR, saying what needs to change. */
    public function reject(TrainingPlan $plan, User $actor, string $comment): void
    {
        $this->ensureCanSign($plan, $actor, 'return');

        DB::transaction(function () use ($plan, $actor, $comment) {
            $this->record($plan, $actor, TrainingPlanSignoff::REJECTED, $comment);

            $plan->update([
                'approval_status'   => ApprovalStatus::REJECTED,
                'current_level'     => null,
                'rejected_by'       => $actor->id,
                'rejected_at'       => now(),
                'rejection_comment' => $comment,
            ]);
        });

        $this->access->syncReviewers();
        $this->notify($plan, 'rejected', $actor, collect([$plan->preparer]), $comment);
    }

    /**
     * Reopen an approved plan so trainings can be added or changed. It goes back to draft and
     * needs signing off again; trainings already approved stay approved and keep running
     * (progress, reminders, certificates) in the meantime.
     */
    public function revise(TrainingPlan $plan, User $actor): void
    {
        if (!$plan->isApproved()) {
            throw new UserFacingException('Only an approved plan can be revised.');
        }

        $plan->update([
            'approval_status' => ApprovalStatus::DRAFT,
            'current_level'   => null,
            'prepared_by'     => null,
            'prepared_at'     => null,
            'approved_by'     => null,
            'approved_at'     => null,
        ]);

        activity('training-plan')->causedBy($actor)->performedOn($plan)->event('revised')
            ->log("Reopened the {$plan->year} training plan for revision");
    }

    /**
     * Who still has to sign the current level for it to be complete: its people, less (when each
     * step needs a different person) whoever prepared the plan or signed an earlier level.
     */
    public function eligibleSigners(TrainingPlan $plan): array
    {
        $level = $plan->currentLevel();
        if (!$level) {
            return [];
        }

        return array_values(array_diff($level['user_ids'], $this->excluded($plan)));
    }

    /** People who signed the current level this round. */
    public function signedCurrentLevel(TrainingPlan $plan): array
    {
        return $plan->signoffs()
            ->where('round', $plan->approval_round)->where('level', $plan->current_level)
            ->where('decision', TrainingPlanSignoff::APPROVED)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The plan's approval levels and where it stands in each: done, current, returned (rejected
     * there) or waiting, with who signed. A plan being prepared shows the levels it will go through.
     */
    public function progress(TrainingPlan $plan): array
    {
        $preparing = $plan->approval_status === ApprovalStatus::DRAFT;
        $chain = $preparing ? TrainingPlanApprovalLevel::snapshot() : ($plan->approval_chain ?? []);

        $signoffs = $preparing ? collect() : $plan->signoffs()->where('round', $plan->approval_round)->get()->groupBy('level');
        $names = User::with('employee')->whereIn('id', collect($chain)->pluck('user_ids')->flatten()->unique())->get()
            ->mapWithKeys(fn (User $u) => [$u->id => ['uuid' => $u->uuid, 'name' => $u->employee?->name ?? $u->name]]);
        $returnedAt = $plan->approval_status === ApprovalStatus::REJECTED
            ? $signoffs->flatten()->firstWhere('decision', TrainingPlanSignoff::REJECTED)?->level
            : null;
        $last = count($chain) - 1;

        return collect($chain)->map(function (array $level, int $i) use ($plan, $preparing, $signoffs, $names, $returnedAt, $last) {
            $status = match (true) {
                $preparing                    => 'waiting',
                $plan->isApproved()           => 'done',
                $returnedAt !== null          => $i < $returnedAt ? 'done' : ($i === $returnedAt ? 'returned' : 'waiting'),
                $plan->current_level === null => 'waiting',
                $i < $plan->current_level     => 'done',
                $i === $plan->current_level   => 'current',
                default                       => 'waiting',
            };
            $byUser = ($signoffs[$i] ?? collect())->keyBy('user_id');

            return [
                'name'   => $level['name'],
                'rule'   => $level['rule'],
                'final'  => $i === $last,
                'status' => $status,
                'people' => collect($level['user_ids'])->filter(fn ($id) => isset($names[$id]))->map(fn ($id) => $names[$id] + [
                    'decision' => $byUser[$id]->decision ?? null,
                    'at'       => $byUser[$id]->created_at ?? null,
                    'comment'  => $byUser[$id]->comment ?? null,
                ])->values()->all(),
            ];
        })->all();
    }

    public function canSign(TrainingPlan $plan, User $user): bool
    {
        try {
            $this->ensureCanSign($plan, $user, 'sign');
            return true;
        } catch (UserFacingException) {
            return false;
        }
    }

    private function approve(TrainingPlan $plan, User $actor): void
    {
        $stamps = [
            'approval_status' => ApprovalStatus::APPROVED,
            'approved_by'     => $actor->id,
            'approved_at'     => now(),
        ];

        $plan->update($stamps + ['current_level' => null]);

        // Trainings added or changed since the last approval are approved with the plan.
        $plan->items()->where('approval_status', '!=', ApprovalStatus::APPROVED->value)->update($stamps + [
            'prepared_by' => $plan->prepared_by,
            'prepared_at' => $plan->prepared_at,
        ]);

        $this->access->syncReviewers();
        $this->notify($plan, 'approved', $actor, collect([$plan->preparer]));
    }

    private function levelComplete(TrainingPlan $plan): bool
    {
        if ($plan->currentLevel()['rule'] === 'any') {
            return true;
        }

        return !array_diff($this->eligibleSigners($plan), $this->signedCurrentLevel($plan));
    }

    private function record(TrainingPlan $plan, User $actor, string $decision, ?string $comment = null): void
    {
        $plan->signoffs()->create([
            'round'    => $plan->approval_round,
            'level'    => $plan->current_level,
            'user_id'  => $actor->id,
            'decision' => $decision,
            'comment'  => $comment,
        ]);
    }

    private function ensureCanSign(TrainingPlan $plan, User $actor, string $action): void
    {
        $level = $plan->currentLevel();
        if (!$level || !in_array($plan->approval_status, [ApprovalStatus::PENDING_VALIDATION, ApprovalStatus::PENDING_APPROVAL], true)) {
            throw new UserFacingException('This plan is not awaiting sign-off.');
        }

        if (!in_array($actor->id, $level['user_ids'], true)) {
            throw new UserFacingException("Only the people in \"{$level['name']}\" can {$action} the plan at this stage.", 403);
        }

        if (in_array($actor->id, $this->excluded($plan), true)) {
            throw new UserFacingException('You prepared this plan or signed it at an earlier level: each step must be done by a different person.', 403);
        }

        if (in_array($actor->id, $this->signedCurrentLevel($plan), true)) {
            throw new UserFacingException('You have already signed this level.');
        }
    }

    /** Who may not sign the current level when each step needs a different person. */
    private function excluded(TrainingPlan $plan): array
    {
        if (!$this->requiresDifferentApprovers()) {
            return [];
        }

        $earlier = $plan->signoffs()
            ->where('round', $plan->approval_round)->where('level', '<', (int) $plan->current_level)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_filter([(int) $plan->prepared_by, ...$earlier])));
    }

    private function ensureChainUsable(array $chain, User $preparer): void
    {
        if (!$chain) {
            throw new UserFacingException('Set up the approval levels (who validates and approves training plans) before submitting.');
        }

        foreach ($chain as $level) {
            $signers = $this->requiresDifferentApprovers()
                ? array_diff($level['user_ids'], [$preparer->id])
                : $level['user_ids'];

            if (!$signers) {
                throw new UserFacingException("Nobody can sign \"{$level['name']}\": add someone to it (other than whoever submits the plan) on the approval levels page.");
            }
        }
    }

    /** Read on each call, so a change in System Features applies immediately. */
    private function requiresDifferentApprovers(): bool
    {
        $value = Setting::where('key', 'features.training_plan.require_different_approvers')->value('value');

        return $value === null ? true : (bool) $value;
    }

    /** Ask the people in the level the plan has reached to sign it. */
    private function notifyLevel(TrainingPlan $plan, User $actor): void
    {
        $level = $plan->currentLevel();
        $event = $plan->isFinalLevel() ? 'approval' : 'validation';

        $this->notify($plan, $event, $actor, User::whereIn('id', $this->eligibleSigners($plan))->get(), level: $level['name']);
    }

    private function notify(TrainingPlan $plan, string $event, User $actor, $recipients, ?string $comment = null, ?string $level = null): void
    {
        $recipients = collect($recipients)->filter()
            ->reject(fn (User $u) => $u->id === $actor->id)
            ->unique('id');

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new TrainingPlanApprovalNotification($plan, $event, $actor, $comment, $level));
        }
    }
}
