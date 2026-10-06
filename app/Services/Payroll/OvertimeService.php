<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Exceptions\UserFacingException;
use App\Models\Holiday;
use App\Models\Payroll\Approval;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\OvertimeType;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunInput;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Overtime requests under the organization's policy (Payroll → Settings → Overtime): checked,
 * sent through the overtime workflow, and once approved, paid by the next regular pay run.
 */
class OvertimeService
{
    public function __construct(private readonly ApprovalWorkflowService $approvals)
    {
    }

    /** Weekday, weekend or holiday. */
    public function dayKind(Carbon $date): string
    {
        $holiday = Holiday::whereDate('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereDate('end_date', '>=', $date)->orWhere(fn ($w) => $w->whereNull('end_date')->whereDate('start_date', $date)))
            ->exists();

        return $holiday ? 'holiday' : ($date->isWeekend() ? 'weekend' : 'weekday');
    }

    /** The active type for a date: one for that kind of day, else one for any day. */
    public function typeFor(Carbon $date): ?OvertimeType
    {
        $types = OvertimeType::where('active', true)->orderBy('sort_order')->orderBy('name')->get();

        return $types->firstWhere('applies_on', $this->dayKind($date)) ?? $types->firstWhere('applies_on', 'any');
    }

    /** Why this employee can't claim overtime, or null when they can. */
    public function ineligibility(Employee $employee): ?string
    {
        $profile = EmployeePayProfile::inForceOn(now())->where('employee_id', $employee->id)->first();
        if ($profile && $profile->overtime_eligible !== null) {
            return $profile->overtime_eligible ? null : 'You are not eligible for overtime. Speak to HR if this is wrong.';
        }
        if (setting('payroll.overtime_eligibility', 'everyone') === 'job_types'
            && !in_array($employee->job_type, (array) setting('payroll.overtime_job_types', []), true)) {
            return 'Overtime isn\'t available for your type of employment.';
        }

        return null;
    }

    /** Problems with a request, in words; empty when it can be sent. */
    public function problems(Employee $employee, ?OvertimeType $type, Carbon $date, float $hours, ?string $reason, ?string $location, ?OvertimeRequest $ignore = null): array
    {
        $problems = array_filter([$this->ineligibility($employee)]);
        $today = now()->startOfDay();

        if (!$type || !$type->active) {
            $problems[] = 'Choose an overtime type.';
        }
        if ($date->gt($today)) {
            $problems[] = 'Overtime is requested after it is worked, not before.';
        }
        $backdate = (int) setting('payroll.overtime_backdate_days', 31);
        if ($date->lt($today->copy()->subDays($backdate))) {
            $problems[] = "Overtime must be requested within {$backdate} days of the work.";
        }

        $others = OvertimeRequest::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id));
        $perDay = (float) setting('payroll.overtime_max_hours_per_day', 12);
        $day = (float) (clone $others)->whereDate('work_date', $date)->sum('hours_requested');
        if ($hours <= 0) {
            $problems[] = 'Enter the hours worked.';
        } elseif ($day + $hours > $perDay) {
            $problems[] = "No more than {$perDay} hours of overtime a day" . ($day ? " ({$day} already requested for this day)" : '') . '.';
        }
        $perMonth = (float) setting('payroll.overtime_max_hours_per_month', 0);
        if ($perMonth > 0) {
            $month = (float) (clone $others)->whereYear('work_date', $date->year)->whereMonth('work_date', $date->month)->sum('hours_requested');
            if ($month + $hours > $perMonth) {
                $problems[] = "No more than {$perMonth} hours of overtime a month ({$month} already requested for " . $date->format('F') . ').';
            }
        }

        if (setting('payroll.overtime_require_reason', true) && blank($reason)) {
            $problems[] = 'Say what the overtime was for.';
        }
        $locations = (array) setting('payroll.overtime_locations', []);
        if (setting('payroll.overtime_require_location', false) && blank($location)) {
            $problems[] = 'Choose where the work was done.';
        } elseif (filled($location) && $locations && !in_array($location, $locations, true)) {
            $problems[] = 'Choose a location from the list.';
        }

        return $problems;
    }

    public function request(Employee $employee, array $data, User $user): OvertimeRequest
    {
        $date = Carbon::parse($data['work_date'])->startOfDay();
        $auto = (bool) setting('payroll.overtime_auto_type', true);
        $type = filled($data['overtime_type_uuid'] ?? null)
            ? OvertimeType::where('uuid', $data['overtime_type_uuid'])->first()
            : ($auto ? $this->typeFor($date) : null);
        // With types chosen by date, only an any-day type (e.g. offshore) can be chosen instead.
        if ($auto && $type && filled($data['overtime_type_uuid'] ?? null) && !in_array($type->applies_on, ['any', $this->dayKind($date)], true)) {
            throw new UserFacingException("{$type->name} overtime isn't for this day.");
        }
        $hours = round((float) $data['hours'], 2);

        if ($problems = $this->problems($employee, $type, $date, $hours, $data['reason'] ?? null, $data['location'] ?? null)) {
            throw new UserFacingException(implode(' ', $problems));
        }

        return DB::transaction(function () use ($employee, $type, $date, $hours, $data, $user) {
            $request = OvertimeRequest::create([
                'employee_id'      => $employee->id,
                'overtime_type_id' => $type->id,
                'work_date'        => $date->toDateString(),
                'hours_requested'  => $hours,
                'approved_hours'   => $hours,
                'location'         => $data['location'] ?? null,
                'reason'           => $data['reason'] ?? null,
                'status'           => 'pending',
                'requested_by'     => $user->id,
            ]);
            $this->approvals->start($request, ApprovalProcess::OVERTIME, $employee, $user);

            return $request;
        });
    }

    public function cancel(OvertimeRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw new UserFacingException('Only a pending request can be withdrawn.');
        }
        DB::transaction(function () use ($request) {
            if ($request->approval) {
                $this->approvals->cancel($request->approval);
            }
            $request->update(['status' => Approval::CANCELLED]);
        });
    }

    /**
     * Bring approved, unpaid overtime worked up to the end of the run's period into a regular run, as
     * one input per employee and overtime component. Recalculating does it again from scratch.
     */
    public function claimFor(PayRun $run): void
    {
        DB::transaction(function () use ($run) {
            $this->release($run);
            if ($run->type !== 'regular' || !feature('payroll.overtime')) {
                return;
            }

            $paid = EmployeePayProfile::inForceOn($run->period_end)->pluck('employee_id');
            $requests = OvertimeRequest::where('status', 'approved')->whereNull('pay_run_id')
                ->whereDate('work_date', '<=', $run->period_end)
                ->whereIn('employee_id', $paid)
                ->with('type')->get();

            foreach ($requests->groupBy(fn ($r) => $r->employee_id . ':' . $r->type->pay_component_id) as $group) {
                $first = $group->first();
                PayRunInput::create([
                    'pay_run_id'       => $run->id,
                    'employee_id'      => $first->employee_id,
                    'pay_component_id' => $first->type->pay_component_id,
                    'quantity'         => $group->sum(fn ($r) => (float) $r->approved_hours),
                    'source'           => 'overtime',
                    'notes'            => $group->count() . ' approved request' . ($group->count() > 1 ? 's' : '') . ', ' . $group->min('work_date')->format('j M') . ($group->count() > 1 ? ' – ' . $group->max('work_date')->format('j M') : ''),
                ]);
            }
            OvertimeRequest::whereIn('id', $requests->pluck('id'))->update(['pay_run_id' => $run->id]);
        });
    }

    /** Hand a run's overtime back (the run is recalculated or removed). */
    public function release(PayRun $run): void
    {
        PayRunInput::where('pay_run_id', $run->id)->where('source', 'overtime')->delete();
        OvertimeRequest::where('pay_run_id', $run->id)->update(['pay_run_id' => null]);
    }
}
