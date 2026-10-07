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
use Illuminate\Support\Carbon;
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
     * The month's sheet: every employee by every per-unit component, filled with what's entered.
     * Entries already in a pay run sent for approval are locked.
     */
    public function template(int $year, int $month): string
    {
        $components = $this->components();
        $employees = Employee::with('department')->orderBy('first_name')->orderBy('last_name')->get();
        $entries = TimeInput::where(['year' => $year, 'month' => $month])->whereIn('status', ['pending', 'approved'])
            ->with(['payRun', 'employee:id,uuid'])->get();
        $period = Carbon::create($year, $month, 1)->format('F Y');

        $columns = $components->map(fn (PayComponent $c) => [
            'ref'     => $c->uuid,
            'heading' => "{$c->name} ({$c->code}) · " . ($c->unit ?: 'units'),
            'values'  => $entries->where('pay_component_id', $c->id)->mapWithKeys(fn ($t) => [$t->employee->uuid => (float) $t->quantity])->all(),
            'locked'  => $entries->where('pay_component_id', $c->id)->filter(fn ($t) => $t->payRun && !$t->payRun->status->isOpen())
                ->mapWithKeys(fn ($t) => [$t->employee->uuid => true])->all(),
        ])->values()->all();

        return Sheets\EmployeeGrid::build("Time inputs {$period}", "time-inputs:" . sprintf('%04d-%02d', $year, $month), $employees, $columns, null, [
            "Time inputs for {$period}",
            'One row per employee, one column per component. Type the quantity for the month; empty or 0 means none.',
            'Staff ID, Name and Department are locked. Hidden references identify each row and column, so rows or columns added by hand are ignored or refused.',
            'Grey cells are already in a pay run sent for approval and can no longer change.',
            'Only cells that differ from what is entered are saved. If any cell has a problem, nothing is saved and the problems are listed.',
        ]);
    }

    /**
     * Import the month's sheet. All or nothing.
     *
     * @return array{saved: int, removed: int, errors: array<int, string>}
     */
    public function importGrid(int $year, int $month, string $path, User $user, bool $apply = true): array
    {
        $days = Carbon::create($year, $month, 1)->daysInMonth;
        $grid = Sheets\EmployeeGrid::read($path);
        $expected = 'time-inputs:' . sprintf('%04d-%02d', $year, $month);
        if ($grid['reference'] !== $expected) {
            $sheetFor = str_starts_with((string) $grid['reference'], 'time-inputs:') ? Carbon::parse(substr($grid['reference'], 12) . '-01')->format('F Y') : null;

            return ['saved' => 0, 'removed' => 0, 'errors' => [1 => $sheetFor
                ? "This sheet is for {$sheetFor}, not " . Carbon::create($year, $month, 1)->format('F Y') . '. Choose that month, or download this month\'s template.'
                : 'This isn\'t a time inputs template. Download the template and fill that in.']];
        }

        $components = $this->components()->keyBy('uuid');
        $employees = Employee::whereIn('uuid', collect($grid['rows'])->pluck('ref')->filter())->get()->keyBy('uuid');
        $entries = TimeInput::where(['year' => $year, 'month' => $month])->whereIn('status', ['pending', 'approved'])->with('payRun')->get()
            ->keyBy(fn ($t) => "{$t->employee_id}:{$t->pay_component_id}");

        $errors = [];
        $plans = [];
        $preview = [];
        foreach ($grid['rows'] as $line => $row) {
            $employee = $row['ref'] ? $employees->get($row['ref']) : null;
            if (!$employee) {
                $errors[$line] = "{$row['label']}: This row isn't from the template. Download a fresh template.";
                continue;
            }
            $fields = [];
            $warnings = [];
            foreach ($row['values'] as $ref => $value) {
                $component = $components->get($ref);
                if (!$component) {
                    if ($value !== null) {
                        $errors[$line] = "{$row['label']}: A column isn't a per-unit component any more. Download a fresh template.";
                    }
                    continue;
                }
                if ($value !== null && (!is_numeric($value) || $value < 0)) {
                    $errors[$line] = "{$row['label']}: {$component->name} must be a number.";
                    continue;
                }
                $quantity = $value === null ? null : round((float) $value, 2);
                $existing = $entries->get("{$employee->id}:{$component->id}");
                if ((float) ($existing?->quantity ?? 0) === (float) ($quantity ?? 0)) {
                    continue;
                }
                if ($existing?->payRun && !$existing->payRun->status->isOpen()) {
                    $errors[$line] = "{$row['label']}: {$component->name} is already in {$existing->payRun->name} and can't change.";
                    continue;
                }
                $plans[] = [$employee, $component, $quantity ?: null];
                $fields[] = ['label' => $component->name, 'from' => $existing ? (string) (float) $existing->quantity : '—', 'to' => $quantity ? $quantity . ' ' . ($component->unit ?: 'units') : 'removed'];
                if ($quantity && str_starts_with((string) $component->unit, 'day') && $quantity > $days) {
                    $warnings[] = "{$quantity} days of {$component->name}, more than the month's {$days}.";
                }
                if ($existing && $quantity && setting('payroll.time_inputs_require_approval', true)) {
                    $warnings[] = "{$component->name} changes, so it goes for approval again.";
                }
            }
            if ($fields && !isset($errors[$line])) {
                $preview[] = ['line' => $line, 'who' => "{$row['label']} · " . trim(preg_replace('/\s+/', ' ', $employee->name)), 'action' => 'Changed', 'fields' => $fields, 'warnings' => array_values(array_unique($warnings))];
            }
        }
        $counts = ['saved' => count(array_filter($plans, fn ($p) => $p[2] !== null)), 'removed' => count(array_filter($plans, fn ($p) => $p[2] === null))];
        if ($errors) {
            return ['saved' => 0, 'removed' => 0, 'errors' => $errors, 'changes' => []];
        }
        if (!$apply) {
            return $counts + ['errors' => [], 'changes' => $preview];
        }

        DB::transaction(function () use ($plans, $year, $month, $user) {
            foreach ($plans as [$employee, $component, $quantity]) {
                $this->save($employee, $component, $year, $month, $quantity, null, $user);
            }
        });

        return $counts + ['errors' => [], 'changes' => $preview];
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
