<?php

namespace App\Services\Appraisal;

use App\Enums\Payroll\ApprovalProcess;
use App\Enums\Payroll\ApproverType;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Appraisal\AssessmentAttemptEvent;
use App\Models\Config\Department;
use App\Models\Payroll\Approval;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Notifications\Appraisal\AppraisalNotification;
use App\Services\Org\ReportingLine;
use App\Services\Payroll\ApprovalWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Endorsement of an appraisal by the heads above the appraiser, before HR. The appraiser is the employee's supervisor
 * (ReportingLine::supervisorOf) and scores it; once the employee acknowledges, each head above endorses it (with an
 * optional comment) or returns it to the appraiser (comment required), in turn. How far up it goes is the setting
 * `appraisal.endorsement_chain`: none (default: straight to HR, as before), a number of heads, up to a level, or the
 * top. Endorsers can't change scores. Runs on the approval engine (process `appraisal`).
 */
class AppraisalEndorsement
{
    public function __construct(private readonly ReportingLine $line, private readonly ApprovalWorkflowService $approvals)
    {
    }

    /** The setting, checked: {stop: none} | {stop: count, count: 1–10} | {stop: level, level} | {stop: top}. */
    public static function rule(): array
    {
        $rule = (array) setting('appraisal.endorsement_chain', ['stop' => 'none']);
        $stop = in_array($rule['stop'] ?? null, ['none', 'count', 'level', 'top'], true) ? $rule['stop'] : 'none';

        return ['stop' => $stop, 'count' => max(1, min(10, (int) ($rule['count'] ?? 1))), 'level' => (int) ($rule['level'] ?? 0)];
    }

    /** The appraiser: the employee's supervisor ("Reports to", else the head of their unit). */
    public function appraiserOf(Employee $employee): ?Employee
    {
        return $this->line->supervisorOf($employee);
    }

    /**
     * The endorsers' steps: the heads above the employee, leaving out them and the appraiser (and heads without an
     * account), as far as the setting goes. Empty: no endorsement.
     */
    public function steps(AssessmentAttempt $attempt): array
    {
        $rule = self::rule();
        $employee = $attempt->user?->employee;
        if ($rule['stop'] === 'none' || !$employee) {
            return [];
        }
        $appraiser = $attempt->supervisor?->employee_id ?? $this->appraiserOf($employee)?->id;
        $links = $this->line->chainAbove($employee, $rule['stop'] === 'level' ? $rule : [])
            ->reject(fn ($l) => $l['head']->id === $appraiser || !$l['head']->userAccount);
        if ($rule['stop'] === 'count') {
            $links = $links->take($rule['count']);
        }

        return $links->values()->map(fn ($l) => [
            'name' => "Head of {$l['department']->name}", 'approver_type' => ApproverType::USERS->value,
            'approver_value' => [$l['head']->userAccount->id],
        ])->all();
    }

    /** After the employee acknowledges: to the endorsers when there are any (true), else nothing to do (false). */
    public function start(AssessmentAttempt $attempt, ?User $by): bool
    {
        $steps = $this->steps($attempt);
        if (!$steps) {
            return false;
        }
        DB::transaction(function () use ($attempt, $by, $steps) {
            $attempt->update(['status' => AssessmentAttempt::STATUS_PENDING_ENDORSEMENT]);
            $this->event($attempt, 'sent_for_endorsement', implode(' → ', array_column($steps, 'name')), $by?->id);
            $this->approvals->startWith($attempt, ApprovalProcess::APPRAISAL, $attempt->user?->employee, $by, $steps);
        });

        return true;
    }

    /** An endorser endorses (comment optional) or returns it to the appraiser (comment required). */
    public function decide(AssessmentAttempt $attempt, User $user, bool $endorse, ?string $comment): Approval
    {
        $approval = $attempt->approval;
        if ($attempt->status !== AssessmentAttempt::STATUS_PENDING_ENDORSEMENT || !$approval?->isPending()) {
            throw new \App\Exceptions\UserFacingException('This appraisal isn\'t waiting for endorsement.');
        }

        return DB::transaction(function () use ($attempt, $approval, $user, $endorse, $comment) {
            $decided = $this->approvals->decide($approval, $user, $endorse ? 'approved' : 'rejected', $comment);
            $this->event($attempt, $endorse ? 'endorsed' : 'endorsement_returned', $comment, $user->id);

            return $decided;
        });
    }

    /** Every head endorsed: to HR. Returned: back to the appraiser, who confirms again (restarting endorsement). */
    public function finished(AssessmentAttempt $attempt, Approval $approval): void
    {
        $attempt->loadMissing('user', 'window', 'supervisor');
        if ($approval->status === Approval::APPROVED) {
            $attempt->update(['status' => AssessmentAttempt::STATUS_SUPERVISOR_CONFIRMED, 'supervisor_confirmed_at' => now()]);
            $this->notifyHr($attempt);
        } elseif ($approval->status === Approval::REJECTED) {
            $attempt->update(['status' => AssessmentAttempt::STATUS_PENDING_SUPERVISOR]);
            $comment = $approval->decisions()->where('decision', 'rejected')->latest('id')->value('comment');
            $attempt->supervisor?->notify(new AppraisalNotification(
                type: 'appraisal_endorsement_returned',
                subject: "{$attempt->user->name}'s appraisal has been returned to you",
                greeting: "Hello {$attempt->supervisor->name},",
                lines: ["It was returned during endorsement of \"{$attempt->window?->title}\".", "Reason: {$comment}", 'Please revise it and confirm it again.'],
            ));
        }
    }

    /** HR (and for HR staff, the appraisal officers) finalize it from here. */
    public function notifyHr(AssessmentAttempt $attempt): void
    {
        $hr = User::whereHas('roles', fn ($q) => $q->where('name', 'hr'))->get();
        if ($hr->isNotEmpty()) {
            Notification::send($hr, new AppraisalNotification(
                type: 'appraisal_forwarded_to_hr',
                subject: "Appraisal Ready for Finalization: {$attempt->user->name}",
                greeting: 'Hello,',
                lines: ["{$attempt->user->name}'s appraisal for \"{$attempt->window?->title}\" has been confirmed and is ready for finalization."],
            ));
        }
    }

    private function event(AssessmentAttempt $attempt, string $type, ?string $comment, ?int $actor): void
    {
        AssessmentAttemptEvent::create(['assessment_attempt_id' => $attempt->id, 'event_type' => $type, 'actor_id' => $actor, 'comment' => $comment]);
    }
}
