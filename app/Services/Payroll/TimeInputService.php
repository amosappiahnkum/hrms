<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Enums\Payroll\ComponentCalculation;
use App\Exceptions\UserFacingException;
use App\Models\Payroll\Approval;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunInput;
use App\Models\Payroll\TimeInput;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly units per employee (offshore days, shifts…): entered or imported, approved through the
 * time_input workflow when the organization asks for it, then paid by the period's pay run.
 */
class TimeInputService
{
    public function __construct(private readonly ApprovalWorkflowService $approvals)
    {
    }

    /** Components entered as units: active, priced per unit. */
    public function components(): Collection
    {
        return PayComponent::where('active', true)->where('is_system', false)
            ->where('calculation', ComponentCalculation::RATE_PER_UNIT)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** The entry that counts for an employee, component and month (pending or approved). */
    public function current(Employee $employee, PayComponent $component, int $year, int $month): ?TimeInput
    {
        return TimeInput::where(['employee_id' => $employee->id, 'pay_component_id' => $component->id, 'year' => $year, 'month' => $month])
            ->whereIn('status', ['pending', 'approved'])->latest('id')->first();
    }

    /**
     * Set the quantity for an employee, component and month. Empty or 0 removes it. A change goes
     * through approval again; once paid it can't change.
     */
    public function save(Employee $employee, PayComponent $component, int $year, int $month, ?float $quantity, ?string $notes, User $user): ?TimeInput
    {
        if (!$this->components()->contains('id', $component->id)) {
            throw new UserFacingException("{$component->name} isn't entered as units.");
        }
        $existing = $this->current($employee, $component, $year, $month);
        if ($existing?->pay_run_id && $existing->payRun?->status->isOpen() === false) {
            throw new UserFacingException("{$employee->name}'s {$component->name} for this month has been paid and can't change.");
        }
        $quantity = $quantity ? round($quantity, 2) : null;
        if ($existing && (float) $existing->quantity === $quantity && $existing->notes === $notes) {
            return $existing;
        }

        return DB::transaction(function () use ($employee, $component, $year, $month, $quantity, $notes, $user, $existing) {
            if ($existing) {
                // In a run still being prepared: its calculation is now out of date.
                if ($existing->payRun?->status === \App\Enums\Payroll\PayRunStatus::CALCULATED) {
                    $existing->payRun->update(['status' => \App\Enums\Payroll\PayRunStatus::DRAFT]);
                }
                if ($existing->approval?->isPending()) {
                    $this->approvals->cancel($existing->approval);
                }
                $existing->update(['status' => Approval::CANCELLED]);
                $existing->delete();
            }
            if (!$quantity) {
                return null;
            }

            $needsApproval = (bool) setting('payroll.time_inputs_require_approval', true);
            $input = TimeInput::create([
                'employee_id' => $employee->id, 'pay_component_id' => $component->id, 'year' => $year, 'month' => $month,
                'quantity' => $quantity, 'notes' => $notes ?: null, 'entered_by' => $user->id,
                'status' => $needsApproval ? 'pending' : 'approved',
            ]);
            if ($needsApproval) {
                $this->approvals->start($input, ApprovalProcess::TIME_INPUT, $employee, $user);
            }

            return $input;
        });
    }

    /**
     * Import rows [staff id, component code, quantity, notes] for a month. All or nothing: any
     * problem and nothing is saved, every problem listed by row.
     *
     * @return array{saved: int, errors: array<int, string>}
     */
    public function import(int $year, int $month, array $rows, User $user): array
    {
        $employees = Employee::whereIn('staff_id', array_filter(array_map(fn ($r) => trim((string) ($r[0] ?? '')), $rows)))->get()->keyBy(fn ($e) => strtolower($e->staff_id));
        $components = $this->components()->keyBy(fn ($c) => strtoupper($c->code));
        $errors = [];
        $valid = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            [$staffId, $code, $quantity, $notes] = array_pad(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row), 4, null);
            if (!$staffId && !$code) {
                continue;
            }
            $employee = $employees->get(strtolower((string) $staffId));
            $component = $components->get(strtoupper((string) $code));
            $problems = array_filter([
                $employee ? null : 'Unknown staff ID.',
                $component ? null : 'Unknown component code (it must be a per-unit component).',
                is_numeric($quantity) && $quantity >= 0 ? null : 'The quantity must be a number.',
            ]);
            $key = strtolower((string) $staffId) . ':' . strtoupper((string) $code);
            if (!$problems && isset($seen[$key])) {
                $problems[] = "Repeats row {$seen[$key]}.";
            }
            if ($problems) {
                $errors[$i + 2] = ($staffId ? "{$staffId}: " : '') . implode(' ', $problems);
                continue;
            }
            $seen[$key] = $i + 2;
            $valid[] = [$employee, $component, (float) $quantity, $notes ?: null];
        }

        if ($errors) {
            return ['saved' => 0, 'errors' => $errors];
        }
        DB::transaction(function () use ($valid, $year, $month, $user) {
            foreach ($valid as [$employee, $component, $quantity, $notes]) {
                $this->save($employee, $component, $year, $month, $quantity, $notes, $user);
            }
        });

        return ['saved' => count($valid), 'errors' => []];
    }

    /** Approved, unpaid inputs up to the run's month join a regular run, one input per employee and component. */
    public function claimFor(PayRun $run): void
    {
        DB::transaction(function () use ($run) {
            $this->release($run);
            if ($run->type !== 'regular' || !feature('payroll.time_inputs')) {
                return;
            }

            $paid = EmployeePayProfile::inForceOn($run->period_end)->pluck('employee_id');
            $inputs = TimeInput::where('status', 'approved')->whereNull('pay_run_id')
                ->where(fn ($q) => $q->where('year', '<', $run->year)->orWhere(fn ($w) => $w->where('year', $run->year)->where('month', '<=', $run->month)))
                ->whereIn('employee_id', $paid)->get();

            foreach ($inputs->groupBy(fn ($i) => $i->employee_id . ':' . $i->pay_component_id) as $group) {
                $first = $group->first();
                $months = $group->map(fn ($i) => $i->periodLabel())->unique()->implode(', ');
                PayRunInput::create([
                    'pay_run_id' => $run->id, 'employee_id' => $first->employee_id, 'pay_component_id' => $first->pay_component_id,
                    'quantity' => $group->sum(fn ($i) => (float) $i->quantity), 'source' => 'time_input', 'notes' => "Time input, {$months}",
                ]);
            }
            TimeInput::whereIn('id', $inputs->pluck('id'))->update(['pay_run_id' => $run->id]);
        });
    }

    public function release(PayRun $run): void
    {
        PayRunInput::where('pay_run_id', $run->id)->where('source', 'time_input')->delete();
        TimeInput::where('pay_run_id', $run->id)->update(['pay_run_id' => null]);
    }
}
