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

    /**
     * Import rows [staff id, component code, quantity, amount, notes]. All or nothing: any problem
     * and nothing is saved, every problem listed by row.
     *
     * @return array{created: int, errors: array<int, string>}
     */
    public function import(PayRun $run, array $rows, User $user): array
    {
        $this->ensureOpen($run);
        $employees = Employee::whereIn('staff_id', array_filter(array_map(fn ($r) => trim((string) ($r[0] ?? '')), $rows)))->get()->keyBy(fn ($e) => strtolower($e->staff_id));
        $components = PayComponent::all()->keyBy(fn ($c) => strtoupper($c->code));
        $errors = [];
        $valid = [];

        foreach ($rows as $i => $row) {
            [$staffId, $code, $quantity, $amount, $notes] = array_pad(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row), 5, null);
            if (!$staffId && !$code) {
                continue;
            }
            $employee = $employees->get(strtolower((string) $staffId));
            $component = $components->get(strtoupper((string) $code));
            $quantity = is_numeric($quantity) ? (float) $quantity : null;
            $amount = is_numeric($amount) ? (float) $amount : null;

            if ($problems = $this->problems($run, $employee, $component, $quantity, $amount)) {
                $errors[$i + 2] = ($staffId ? "{$staffId}: " : '') . implode(' ', $problems);
                continue;
            }
            $valid[] = ['pay_run_id' => $run->id, 'employee_id' => $employee->id, 'pay_component_id' => $component->id, 'quantity' => $quantity, 'amount' => $amount, 'notes' => $notes ?: null, 'source' => 'input', 'created_by' => $user->id];
        }

        if ($errors) {
            return ['created' => 0, 'errors' => $errors];
        }

        DB::transaction(function () use ($valid) {
            foreach ($valid as $values) {
                PayRunInput::create($values);
            }
        });
        if ($valid) {
            $this->changed($run);
        }

        return ['created' => count($valid), 'errors' => []];
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
