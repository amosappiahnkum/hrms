<?php

namespace App\Services\Payroll;

use App\Contracts\ApprovalSubject;
use App\Enums\Payroll\ApprovalProcess;
use App\Enums\Payroll\ApproverType;
use App\Exceptions\UserFacingException;
use App\Models\Payroll\Approval;
use App\Models\Payroll\ApprovalWorkflow;
use App\Models\Payroll\ApprovalWorkflowStep;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Notifications\ApprovalRequestNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs requests through the approval workflows HR configures. A request copies the workflow's
 * steps when it starts; each step's approvers are resolved for the employee when it is reached.
 * A step that resolves to nobody goes to the people who configure payroll, never silently skipped.
 */
class ApprovalWorkflowService
{
    /** The workflow a process uses by default. */
    public function defaultFor(ApprovalProcess $process): ?ApprovalWorkflow
    {
        return ApprovalWorkflow::where('process', $process)->where('is_default', true)->with('steps')->first();
    }

    /** Who would approve each step for this employee (for previews and for starting). */
    public function resolve(ApprovalWorkflowStep|array $step, ?Employee $employee): Collection
    {
        $type = $step instanceof ApprovalWorkflowStep ? $step->approver_type : ApproverType::from($step['approver_type']);
        $value = $step instanceof ApprovalWorkflowStep ? $step->approver_value : ($step['approver_value'] ?? null);
        $employee?->loadMissing('employeeSupervisor.supervisor.userAccount', 'department.parent');

        $users = match ($type) {
            ApproverType::SUPERVISOR             => collect([$employee?->employeeSupervisor?->supervisor?->userAccount]),
            ApproverType::DEPARTMENT_HEAD        => collect([$employee?->hodUser()]),
            ApproverType::PARENT_DEPARTMENT_HEAD => collect([$this->parentHead($employee)]),
            ApproverType::ROLE                   => $value ? User::role($value[0] ?? $value)->get() : collect(),
            ApproverType::PERMISSION             => $value ? User::permission($value[0] ?? $value)->get() : collect(),
            ApproverType::USERS                  => User::whereIn('id', (array) $value)->get(),
        };

        return $users->filter()->unique('id')->values();
    }

    /** Start a request on its workflow (or, with none set up, on a single HR step). */
    public function start(Model&ApprovalSubject $subject, ApprovalProcess $process, ?Employee $employee, ?User $startedBy, ?ApprovalWorkflow $workflow = null): Approval
    {
        $workflow ??= $this->defaultFor($process);
        $workflow?->loadMissing('steps');

        $steps = ($workflow?->steps ?? collect())->map(fn (ApprovalWorkflowStep $s) => [
            'position'       => $s->position,
            'name'           => $s->name,
            'approver_type'  => $s->approver_type->value,
            'approver_value' => $s->approver_value,
            'can_adjust'     => array_values(array_intersect($s->can_adjust ?? [], array_keys($process->adjustable()))),
        ])->values()->all();

        // No workflow set up: HR (whoever configures payroll) approves, rather than nobody.
        $steps = $steps ?: [[
            'position' => 1, 'name' => 'HR approval', 'approver_type' => ApproverType::PERMISSION->value,
            'approver_value' => ['configure-payroll'], 'can_adjust' => array_keys($process->adjustable()),
        ]];

        $approval = Approval::create([
            'subject_type'         => $subject->getMorphClass(),
            'subject_id'           => $subject->getKey(),
            'process'              => $process,
            'approval_workflow_id' => $workflow?->id,
            'employee_id'          => $employee?->id,
            'status'               => Approval::PENDING,
            'steps'                => $steps,
            'distinct_approvers'   => (bool) $workflow?->distinct_approvers,
            'started_by'           => $startedBy?->id,
        ]);

        $this->moveTo($approval, $steps[0]['position']);

        return $approval;
    }

    /** Users who may decide the current step. */
    public function canDecide(Approval $approval, User $user): bool
    {
        return $approval->isPending() && in_array($user->id, $approval->current_approver_ids ?? [], true);
    }

    /**
     * Approve or reject the current step. Adjustments are allowed only for the fields the step
     * permits; approving the last step approves the request.
     */
    public function decide(Approval $approval, User $user, string $decision, ?string $comment = null, array $adjustments = []): Approval
    {
        if (!$this->canDecide($approval, $user)) {
            throw new UserFacingException('This request isn\'t waiting for your decision.', 403);
        }
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new UserFacingException('Choose approve or reject.');
        }
        if ($decision === 'rejected' && blank($comment)) {
            throw new UserFacingException('Say why the request is rejected.');
        }

        $step = $approval->currentStep();
        $notAllowed = array_diff(array_keys($adjustments), $step['can_adjust'] ?? []);
        if ($notAllowed) {
            throw new UserFacingException('This step can\'t change: ' . implode(', ', $notAllowed) . '.');
        }

        return DB::transaction(function () use ($approval, $user, $decision, $comment, $adjustments, $step) {
            $subject = $approval->subject;
            $changes = [];
            if ($adjustments && $decision === 'approved' && $subject instanceof ApprovalSubject) {
                $before = $subject->adjustableValues();
                foreach ($adjustments as $field => $value) {
                    if (($before[$field] ?? null) != $value) {
                        $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
                    }
                }
                $subject->applyAdjustments($adjustments);
            }

            $approval->decisions()->create([
                'position'    => $step['position'],
                'step_name'   => $step['name'],
                'decision'    => $decision,
                'decided_by'  => $user->id,
                'comment'     => $comment,
                'adjustments' => $changes ?: null,
            ]);

            if ($decision === 'rejected') {
                $this->finish($approval, Approval::REJECTED);
            } elseif ($next = collect($approval->steps)->first(fn ($s) => $s['position'] > $step['position'])) {
                $this->moveTo($approval, $next['position']);
            } else {
                $this->finish($approval, Approval::APPROVED);
            }

            return $approval->fresh();
        });
    }

    /** Withdrawn by the requester (or HR) while still pending. */
    public function cancel(Approval $approval): void
    {
        if ($approval->isPending()) {
            $approval->update(['status' => Approval::CANCELLED, 'current_approver_ids' => null, 'completed_at' => now()]);
        }
    }

    /** Make a step current: resolve its approvers (falling back to payroll configurers) and tell them. */
    private function moveTo(Approval $approval, int $position): void
    {
        $step = collect($approval->steps)->firstWhere('position', $position);
        $users = $this->resolve($step, $approval->employee);

        // Nobody resolves: route to the people who configure payroll rather than skip a step.
        if ($users->isEmpty()) {
            $users = User::permission('configure-payroll')->get();
        }

        // The requester never approves their own request; with distinct approvers, earlier deciders can't act again.
        $excluded = collect([$approval->employee?->userAccount?->id]);
        if ($approval->distinct_approvers) {
            $excluded = $excluded->merge($approval->decisions()->pluck('decided_by'));
        }
        $users = $users->reject(fn (User $u) => $excluded->contains($u->id))->values();
        if ($users->isEmpty()) {
            $users = User::permission('configure-payroll')->get()->reject(fn (User $u) => $excluded->contains($u->id))->values();
        }
        // Still nobody (a small team): HR, never the requester, so the request can't get stuck.
        if ($users->isEmpty()) {
            $users = User::permission('configure-payroll')->get()->reject(fn (User $u) => $u->id === $approval->employee?->userAccount?->id)->values();
        }

        $approval->update(['current_position' => $position, 'current_approver_ids' => $users->pluck('id')->all()]);

        foreach ($users as $user) {
            $user->notify(new ApprovalRequestNotification($approval, $step['name']));
        }
    }

    private function finish(Approval $approval, string $status): void
    {
        $approval->update(['status' => $status, 'current_position' => null, 'current_approver_ids' => null, 'completed_at' => now()]);

        if ($approval->subject instanceof ApprovalSubject) {
            $approval->subject->approvalFinished($approval->fresh());
        }
    }

    private function parentHead(?Employee $employee): ?User
    {
        $parent = $employee?->department?->parent;
        if (!$parent?->hod) {
            return null;
        }

        return User::where('employee_id', $parent->hod)->first();
    }
}
