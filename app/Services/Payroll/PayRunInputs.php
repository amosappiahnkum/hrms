<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\ComponentCalculation;
use App\Enums\Payroll\PayRunStatus;
use App\Exceptions\UserFacingException;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunInput;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** A pay run's variable items: checked, saved, imported, and handed to the calculator. */
class PayRunInputs
{
    /** @return array<int, array<array{component: PayComponent, quantity: ?float, amount: ?float, source: string}>> by employee id */
    public function forRun(PayRun $run): array
    {
        return PayRunInput::where('pay_run_id', $run->id)->with('component')->get()
            ->groupBy('employee_id')
            ->map(fn ($items) => $items->map(fn (PayRunInput $i) => [
                'component' => $i->component,
                'quantity'  => $i->quantity !== null ? (float) $i->quantity : null,
                'amount'    => $i->amount !== null ? (float) $i->amount : null,
                'source'    => $i->source === 'input' ? 'input' : $i->source,
            ])->all())
            ->all();
    }

    /** Problems with one input, in words; empty when it can be saved. */
    public function problems(PayRun $run, ?Employee $employee, ?PayComponent $component, ?float $quantity, ?float $amount): array
    {
        $problems = [];
        if (!$employee) {
            $problems[] = 'Unknown employee.';
        } elseif (!EmployeePayProfile::inForceOn($run->period_end)->where('employee_id', $employee->id)->exists()) {
            $problems[] = "{$employee->name} has no pay details for this period.";
        }
        if (!$component || !$component->active || $component->is_system) {
            $problems[] = 'Unknown or inactive component.';
        } else {
            $needsQuantity = in_array($component->calculation, [ComponentCalculation::HOURLY_MULTIPLIER, ComponentCalculation::RATE_PER_UNIT], true);
            if ($needsQuantity && !$quantity) {
                $problems[] = "{$component->name} needs a quantity (" . ($component->unit ?: 'hours') . ').';
            }
            if ($component->calculation === ComponentCalculation::MANUAL && $amount === null) {
                $problems[] = "{$component->name} needs an amount.";
            }
        }

        return $problems;
    }

    public function save(PayRun $run, array $data, User $user, ?PayRunInput $input = null): PayRunInput
    {
        $this->ensureOpen($run);
        $this->ensureEditable($input);
        $employee = $input?->employee ?? Employee::where('uuid', $data['employee_uuid'] ?? null)->first();
        $component = $input?->component ?? PayComponent::where('uuid', $data['pay_component_uuid'] ?? null)->first();
        $quantity = isset($data['quantity']) ? (float) $data['quantity'] : null;
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;

        if ($problems = $this->problems($run, $employee, $component, $quantity, $amount)) {
            throw new UserFacingException(implode(' ', $problems));
        }

        $values = ['quantity' => $quantity, 'amount' => $amount, 'notes' => $data['notes'] ?? null];
        $saved = $input
            ? tap($input)->update($values)
            : PayRunInput::create($values + ['pay_run_id' => $run->id, 'employee_id' => $employee->id, 'pay_component_id' => $component->id, 'source' => 'input', 'created_by' => $user->id]);
        $this->changed($run);

        return $saved;
    }

    public function delete(PayRun $run, PayRunInput $input): void
    {
        $this->ensureOpen($run);
        $this->ensureEditable($input);
        $input->delete();
        $this->changed($run);
    }

    /** Components a pay run's inputs can use: one-off items, and those paid by the hour or unit. */
    public function inputComponents(PayRun $run): Collection
    {
        $used = PayRunInput::where('pay_run_id', $run->id)->where('source', 'input')->pluck('pay_component_id');

        return PayComponent::where('is_system', false)
            ->where(fn ($q) => $q->where('active', true)->where(fn ($w) => $w->where('recurring', false)
                ->orWhereIn('calculation', [ComponentCalculation::HOURLY_MULTIPLIER, ComponentCalculation::RATE_PER_UNIT]))
                ->orWhereIn('id', $used))
            ->orderBy('kind')->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Whether a component's cell holds a quantity (hours, units) rather than an amount. */
    private function byQuantity(PayComponent $c): bool
    {
        return in_array($c->calculation, [ComponentCalculation::HOURLY_MULTIPLIER, ComponentCalculation::RATE_PER_UNIT], true);
    }

    /**
     * The run's sheet: everyone who can be paid in it, by component, filled with the inputs entered by hand.
     * Inputs that came from approved overtime, time inputs, loans or back pay aren't in it.
     */
    public function template(PayRun $run): string
    {
        $components = $this->inputComponents($run);
        $inputs = PayRunInput::where('pay_run_id', $run->id)->where('source', 'input')->with('employee:id,uuid')->get();
        $employees = Employee::with('department')
            ->where(fn ($q) => $q->whereIn('id', EmployeePayProfile::inForceOn($run->period_end)->select('employee_id'))->orWhereIn('id', $inputs->pluck('employee_id')))
            ->orderBy('first_name')->orderBy('last_name')->get();
        $closed = !$run->status->isOpen();
        $everyone = $closed ? $employees->mapWithKeys(fn ($e) => [$e->uuid => true])->all() : [];

        $columns = $components->map(function (PayComponent $c) use ($inputs, $everyone) {
            $mine = $inputs->where('pay_component_id', $c->id)->groupBy(fn ($i) => $i->employee->uuid);

            return [
                'ref'     => $c->uuid,
                'heading' => "{$c->name} ({$c->code}) · " . ($this->byQuantity($c) ? ($c->unit ?: 'hours') : 'amount' . ($c->currency ? " {$c->currency}" : '')),
                'values'  => $mine->map(fn ($items) => $this->byQuantity($c)
                    ? (float) $items->sum('quantity')
                    : (float) $items->sum(fn ($i) => $i->amount ?? $c->rate))->all(),
                'locked'  => $everyone,
            ];
        })->values()->all();
        $notes = ['heading' => 'Notes', 'values' => $inputs->groupBy(fn ($i) => $i->employee->uuid)->map(fn ($items) => $items->pluck('notes')->filter()->first())->all()];

        return Sheets\EmployeeGrid::build('Inputs', "pay-run:{$run->uuid}", $employees, $columns, $notes, [
            "Inputs for {$run->name}",
            'One row per employee who can be paid in this run, one column per component. Hours or units for components paid by the hour or unit; an amount for the others.',
            'The sheet is filled with the inputs entered by hand. Change a cell to change it, empty it to remove it. Notes apply to that employee\'s inputs.',
            'Approved overtime, time inputs, loan repayments and back pay come in on their own and are not in this sheet.',
            'Staff ID, Name and Department are locked. Hidden references identify each row and column.',
            'Only cells that differ are saved. If any cell has a problem, nothing is saved and the problems are listed.',
        ]);
    }

    /**
     * Import the run's sheet. All or nothing.
     *
     * @return array{created: int, updated: int, removed: int, errors: array<int, string>}
     */
    public function importGrid(PayRun $run, string $path, User $user, bool $apply = true): array
    {
        $this->ensureOpen($run);
        $days = $run->period_start->daysInMonth;
        $grid = Sheets\EmployeeGrid::read($path);
        if ($grid['reference'] !== "pay-run:{$run->uuid}") {
            return ['created' => 0, 'updated' => 0, 'removed' => 0, 'errors' => [1 => str_starts_with((string) $grid['reference'], 'pay-run:')
                ? 'This sheet is for another pay run. Download this run\'s template.'
                : 'This isn\'t a pay run inputs template. Download the template and fill that in.']];
        }

        $components = PayComponent::whereIn('uuid', collect($grid['rows'])->flatMap(fn ($r) => array_keys($r['values']))->unique())->get()->keyBy('uuid');
        $employees = Employee::whereIn('uuid', collect($grid['rows'])->pluck('ref')->filter())->get()->keyBy('uuid');
        $existing = PayRunInput::where('pay_run_id', $run->id)->where('source', 'input')->orderBy('id')->get()
            ->groupBy(fn ($i) => "{$i->employee_id}:{$i->pay_component_id}");

        $errors = [];
        $plans = [];
        $preview = [];
        foreach ($grid['rows'] as $line => $row) {
            $employee = $row['ref'] ? $employees->get($row['ref']) : null;
            if (!$employee) {
                $errors[$line] = "{$row['label']}: This row isn't from the template. Download a fresh template.";
                continue;
            }
            $notes = filled($row['notes']) ? mb_substr((string) $row['notes'], 0, 255) : null;
            $problems = [];
            $fields = [];
            $warnings = [];
            $show = fn (PayComponent $c, $items) => $items->isEmpty() ? '—' : (string) (float) ($this->byQuantity($c) ? $items->sum('quantity') : $items->sum(fn ($i) => $i->amount ?? $c->rate));
            foreach ($row['values'] as $ref => $value) {
                $component = $components->get($ref);
                $items = $component ? $existing->get("{$employee->id}:{$component->id}", collect()) : collect();
                if (!$component) {
                    if ($value !== null) {
                        $problems[] = 'A column isn\'t a component any more. Download a fresh template.';
                    }
                    continue;
                }
                if ($value === null) {
                    if ($items->isNotEmpty()) {
                        $plans[] = ['remove', $items];
                        $fields[] = ['label' => $component->name, 'from' => $show($component, $items), 'to' => 'removed'];
                    }
                    continue;
                }
                if (!is_numeric($value) || $value < 0) {
                    $problems[] = "{$component->name} must be a number.";
                    continue;
                }
                $quantity = $this->byQuantity($component) ? round((float) $value, 2) : null;
                $amount = $this->byQuantity($component) ? null : round((float) $value, 2);
                if ($found = $this->problems($run, $employee, $component, $quantity, $amount)) {
                    $problems = [...$problems, ...$found];
                    continue;
                }
                $first = $items->first();
                $same = $items->count() === 1
                    && (float) ($first->quantity ?? 0) === (float) ($quantity ?? 0)
                    && (float) ($first->amount ?? ($quantity === null ? $component->rate : 0)) === (float) ($amount ?? 0)
                    && $first->notes === $notes;
                if (!$same) {
                    $fields[] = ['label' => $component->name, 'from' => $show($component, $items), 'to' => (string) (float) $value . ($this->byQuantity($component) ? ' ' . ($component->unit ?: 'h') : '')];
                    if ($component->calculation === ComponentCalculation::HOURLY_MULTIPLIER && $value > 100) {
                        $warnings[] = "{$value} hours of {$component->name} in one month.";
                    }
                    if ($component->calculation === ComponentCalculation::RATE_PER_UNIT && str_starts_with((string) $component->unit, 'day') && $value > $days) {
                        $warnings[] = "{$value} days of {$component->name}, more than the month's {$days}.";
                    }
                    $plans[] = $first
                        ? ['update', $items, ['quantity' => $quantity, 'amount' => $amount, 'notes' => $notes]]
                        : ['create', ['pay_run_id' => $run->id, 'employee_id' => $employee->id, 'pay_component_id' => $component->id, 'quantity' => $quantity, 'amount' => $amount, 'notes' => $notes, 'source' => 'input', 'created_by' => $user->id]];
                }
            }
            if ($problems) {
                $errors[$line] = "{$row['label']}: " . implode(' ', array_unique($problems));
            } elseif ($fields) {
                $preview[] = ['line' => $line, 'who' => "{$row['label']} · " . trim(preg_replace('/\s+/', ' ', $employee->name)), 'action' => 'Changed', 'fields' => $fields, 'warnings' => $warnings];
            }
        }
        $planned = collect($plans)->countBy(fn ($p) => $p[0]);
        $counts = ['created' => $planned['create'] ?? 0, 'updated' => $planned['update'] ?? 0, 'removed' => $planned['remove'] ?? 0];
        if ($errors) {
            return ['created' => 0, 'updated' => 0, 'removed' => 0, 'errors' => $errors, 'changes' => []];
        }
        if (!$apply) {
            return $counts + ['errors' => [], 'changes' => $preview];
        }

        $counts = ['created' => 0, 'updated' => 0, 'removed' => 0];
        DB::transaction(function () use ($plans, &$counts) {
            foreach ($plans as $plan) {
                match ($plan[0]) {
                    'create' => PayRunInput::create($plan[1]),
                    // One input per employee and component: the first is kept, any others go.
                    'update' => [$plan[1]->first()->update($plan[2]), $plan[1]->slice(1)->each->delete()],
                    'remove' => $plan[1]->each->delete(),
                };
                $counts[['create' => 'created', 'update' => 'updated', 'remove' => 'removed'][$plan[0]]]++;
            }
        });
        if ($plans) {
            $this->changed($run);
        }

        return $counts + ['errors' => [], 'changes' => $preview];
    }

    private function ensureOpen(PayRun $run): void
    {
        if (!$run->status->isOpen()) {
            throw new UserFacingException('Inputs can only change while the pay run is a draft or calculated.');
        }
    }

    /** Approved overtime and time inputs are changed where they were approved, not here. */
    private function ensureEditable(?PayRunInput $input): void
    {
        if ($input && $input->source !== 'input') {
            throw new UserFacingException('This came from approved requests. Change it there; recalculating brings it in again.');
        }
    }

    /** Changed inputs make the calculation out of date. */
    private function changed(PayRun $run): void
    {
        if ($run->status === PayRunStatus::CALCULATED) {
            $run->update(['status' => PayRunStatus::DRAFT]);
        }
    }
}
