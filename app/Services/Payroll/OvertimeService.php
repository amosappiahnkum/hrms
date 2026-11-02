<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Exceptions\UserFacingException;
use App\Models\Holiday;
use App\Models\Payroll\Approval;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\OvertimeRequestGroup;
use App\Models\Payroll\OvertimeType;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunInput;
use App\Models\Payroll\WorkOrder;
use App\Models\Payroll\WorkSite;
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

    /**
     * The type paid on top of overtime worked at an offshore site (claimed on a timesheet): the active any-day type,
     * the first by order when there are several.
     */
    public function onTopForOffshore(): ?OvertimeType
    {
        return OvertimeType::where('active', true)->where('applies_on', 'any')->orderBy('sort_order')->orderBy('name')->first();
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

    /** Where overtime can be worked: the active work sites, or (until any are added) the locations setting. */
    public function locations(): array
    {
        $sites = WorkSite::where('active', true)->orderBy('name')->pluck('name');

        return $sites->isNotEmpty() ? $sites->all() : array_values((array) setting('payroll.overtime_locations', []));
    }

    /**
     * When overtime is requested (setting `payroll.overtime_mode`): `after` it's worked (claimed), `before` it's
     * worked (FM-07, paid as approved), or `both` (approved before, then paid on the hours confirmed as worked).
     */
    public function mode(): string
    {
        return in_array($mode = setting('payroll.overtime_mode', 'after'), ['after', 'before', 'both'], true) ? $mode : 'after';
    }

    public function inAdvance(): bool
    {
        return $this->mode() !== 'after';
    }

    /** Whether overtime on this kind of day needs a request approved before the work (else it's claimed on the timesheet). */
    public function needsAdvance(string $kind): bool
    {
        return $this->inAdvance() && in_array($kind, WorkingDay::advanceKinds(), true);
    }

    /**
     * Problems with a request, in words; empty when it can be sent.
     *
     * @param array{reason?: ?string, location?: ?string, work_order_id?: ?int, activity_description?: ?string} $details
     */
    public function problems(Employee $employee, ?OvertimeType $type, Carbon $date, float $hours, array $details, ?OvertimeRequest $ignore = null): array
    {
        $problems = array_filter([$this->ineligibility($employee)]);
        $today = now()->startOfDay();
        $reason = $details['reason'] ?? null;
        $location = $details['location'] ?? null;

        if (!$type || !$type->active) {
            $problems[] = 'Choose an overtime type.';
        }
        if ($this->inAdvance()) {
            // FM-07: approved before the work is done.
            $ahead = (int) setting('payroll.overtime_advance_days', 30);
            if ($date->lt($today)) {
                $problems[] = 'Overtime is requested before it is worked, not after.';
            } elseif ($date->gt($today->copy()->addDays($ahead))) {
                $problems[] = "Overtime can be requested up to {$ahead} days ahead.";
            }
            if (empty($details['work_order_id']) && blank($details['activity_description'] ?? null)) {
                $problems[] = 'Choose the PO, work order or project, or describe the activities.';
            }
        } else {
            if ($date->gt($today)) {
                $problems[] = 'Overtime is requested after it is worked, not before.';
            }
            $backdate = (int) setting('payroll.overtime_backdate_days', 31);
            if ($date->lt($today->copy()->subDays($backdate))) {
                $problems[] = "Overtime must be requested within {$backdate} days of the work.";
            }
        }

        $others = OvertimeRequest::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id));
        $perDay = (float) setting('payroll.overtime_max_hours_per_day', 12);
        $day = (float) (clone $others)->whereDate('work_date', $date)->sum('hours_requested');
        if ($hours <= 0) {
            $problems[] = $this->inAdvance() ? 'Enter the hours needed.' : 'Enter the hours worked.';
        } elseif ($day + $hours > $perDay) {
            $problems[] = "No more than {$perDay} hours of overtime a day" . ($day ? " ({$day} already requested for this day)" : '') . '.';
        }
        $perMonth = (float) setting('payroll.overtime_max_hours_per_month', 0);
        if ($perMonth > 0) {
            // The month is the pay period the date falls in (e.g. 21 Aug – 20 Sep).
            $period = PayPeriod::containing($date);
            $month = (float) (clone $others)->whereDate('work_date', '>=', $period->start)->whereDate('work_date', '<=', $period->end)->sum('hours_requested');
            if ($month + $hours > $perMonth) {
                $problems[] = "No more than {$perMonth} hours of overtime a month ({$month} already requested for {$period->label()}).";
            }
        }

        if (setting('payroll.overtime_require_reason', true) && blank($reason)) {
            $problems[] = $this->inAdvance() ? 'Give the reason it is urgent.' : 'Say what the overtime was for.';
        }
        $locations = $this->locations();
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
        [$type, $addon] = $this->typesFor($data, $date);
        [$site, $details] = $this->sharedDetails($data);
        $hours = round((float) $data['hours'], 2);

        if ($problems = $this->problems($employee, $type, $date, $hours, $details)) {
            throw new UserFacingException(implode(' ', $problems));
        }

        return DB::transaction(function () use ($employee, $type, $addon, $date, $hours, $details, $site, $user) {
            $request = $this->make($employee, $type, $addon, $date, $hours, $details, $site, $user);
            $this->approvals->start($request, ApprovalProcess::OVERTIME, $employee, $user);

            return $request;
        });
    }

    /**
     * Several dates at once with the same details (e.g. every Saturday this month on one PO): a request per date
     * (each its own type, limits, timesheet and pay), in a group that goes through approval once. Every date is
     * checked as a single request would be, and the monthly limit across them all.
     *
     * @param array{dates: array<int, array{date: string, hours: float}>} $data and the shared details
     */
    public function requestMany(Employee $employee, array $data, User $user): OvertimeRequestGroup
    {
        $dates = collect($data['dates'] ?? [])->map(fn ($d) => ['date' => Carbon::parse($d['date'])->startOfDay(), 'hours' => round((float) ($d['hours'] ?? 0), 2)])
            ->sortBy(fn ($d) => $d['date']->timestamp)->values();
        if ($dates->count() < 2 || $dates->count() > 31) {
            throw new UserFacingException('Choose 2 to 31 dates (one date is a single request).');
        }
        if ($dates->pluck('date')->map->toDateString()->duplicates()->isNotEmpty()) {
            throw new UserFacingException('Each date can be in the request once.');
        }
        [$site, $details] = $this->sharedDetails($data);

        $plans = [];
        $byDate = []; // [date label, problem]
        foreach ($dates as $d) {
            $on = $d['date']->format('D j M');
            try {
                [$type, $addon] = $this->typesFor($data, $d['date']);
            } catch (UserFacingException $e) {
                $byDate[] = [$on, $e->getMessage()];
                continue;
            }
            foreach ($this->problems($employee, $type, $d['date'], $d['hours'], $details) as $p) {
                $byDate[] = [$on, $p];
            }
            $plans[] = [$type, $addon, $d['date'], $d['hours']];
        }
        // A problem on every date (e.g. not eligible, no reason given) is said once; the rest name their date.
        $everywhere = collect($byDate)->groupBy(1)->filter(fn ($on) => $on->count() === $dates->count())->keys();
        $problems = $everywhere->merge(collect($byDate)->reject(fn ($p) => $everywhere->contains($p[1]))->map(fn ($p) => "{$p[0]}: {$p[1]}"))->values()->all();
        // The monthly limit counts every date in the request (each was checked against what's already requested).
        $perMonth = (float) setting('payroll.overtime_max_hours_per_month', 0);
        if ($perMonth > 0) {
            foreach ($dates->groupBy(fn ($d) => PayPeriod::containing($d['date'])->label()) as $label => $inPeriod) {
                $period = PayPeriod::containing($inPeriod->first()['date']);
                $already = (float) OvertimeRequest::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
                    ->whereDate('work_date', '>=', $period->start)->whereDate('work_date', '<=', $period->end)->sum('hours_requested');
                if ($already + $inPeriod->sum('hours') > $perMonth) {
                    $problems[] = "No more than {$perMonth} hours of overtime a month: {$label} would have " . ($already + $inPeriod->sum('hours')) . '.';
                }
            }
        }
        if ($problems) {
            throw new UserFacingException(implode(' ', $problems));
        }

        return DB::transaction(function () use ($employee, $plans, $details, $site, $user) {
            $group = OvertimeRequestGroup::create(['employee_id' => $employee->id, 'status' => 'pending', 'requested_by' => $user->id]);
            foreach ($plans as [$type, $addon, $date, $hours]) {
                $this->make($employee, $type, $addon, $date, $hours, $details, $site, $user, $group);
            }
            $this->approvals->start($group, ApprovalProcess::OVERTIME, $employee, $user);

            return $group;
        });
    }

    /**
     * The type for a date (chosen, or from the date), and the any-day type paid on top (e.g. offshore). With types
     * from the date only an any-day type can be chosen, and one chosen as the type (older screens) goes on top.
     *
     * @return array{0: ?OvertimeType, 1: ?OvertimeType}
     */
    private function typesFor(array $data, Carbon $date): array
    {
        $auto = (bool) setting('payroll.overtime_auto_type', true);
        $type = filled($data['overtime_type_uuid'] ?? null)
            ? OvertimeType::where('uuid', $data['overtime_type_uuid'])->first()
            : ($auto ? $this->typeFor($date) : null);
        if ($auto && $type && filled($data['overtime_type_uuid'] ?? null) && !in_array($type->applies_on, ['any', $this->dayKind($date)], true)) {
            throw new UserFacingException("{$type->name} overtime isn't for this day.");
        }
        $addon = filled($data['addon_type_uuid'] ?? null) ? OvertimeType::where('uuid', $data['addon_type_uuid'])->first() : null;
        if (filled($data['addon_type_uuid'] ?? null) && (!$addon || !$addon->active || $addon->applies_on !== 'any')) {
            throw new UserFacingException('Choose an overtime type that can be paid on top (e.g. Offshore).');
        }
        if ($auto && $type && $type->applies_on === 'any' && filled($data['overtime_type_uuid'] ?? null)) {
            $addon = $type;
            $type = $this->typeFor($date);
        }

        return [$type, $addon];
    }

    /**
     * Where and what for: the site (by uuid, or by name from the list; else the old list's free-text location), the
     * PO or work order, the activities and the reason.
     *
     * @return array{0: ?WorkSite, 1: array{reason: ?string, location: ?string, work_order_id: ?int, activity_description: ?string}}
     */
    private function sharedDetails(array $data): array
    {
        $site = filled($data['work_site_uuid'] ?? null)
            ? WorkSite::where('uuid', $data['work_site_uuid'])->where('active', true)->first()
            : (filled($data['location'] ?? null) ? WorkSite::where('name', $data['location'])->where('active', true)->first() : null);
        if (filled($data['work_site_uuid'] ?? null) && !$site) {
            throw new UserFacingException('Choose an active site.');
        }
        $order = filled($data['work_order_uuid'] ?? null) ? WorkOrder::where('uuid', $data['work_order_uuid'])->where('active', true)->first() : null;
        if (filled($data['work_order_uuid'] ?? null) && !$order) {
            throw new UserFacingException('Choose an active PO or work order.');
        }

        return [$site, [
            'reason' => $data['reason'] ?? null, 'location' => $site?->name ?? ($data['location'] ?? null),
            'work_order_id' => $order?->id, 'activity_description' => $data['activity_description'] ?? null,
        ]];
    }

    private function make(Employee $employee, OvertimeType $type, ?OvertimeType $addon, Carbon $date, float $hours, array $details, ?WorkSite $site, User $user, ?OvertimeRequestGroup $group = null): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id'               => $employee->id,
            'overtime_request_group_id' => $group?->id,
            'overtime_type_id'          => $type->id,
            'addon_type_id'             => $addon?->id,
            'work_date'                 => $date->toDateString(),
            'hours_requested'           => $hours,
            'approved_hours'            => $hours,
            'location'                  => $details['location'],
            'work_site_id'              => $site?->id,
            'work_order_id'             => $details['work_order_id'],
            'activity_description'      => $details['activity_description'],
            'reason'                    => $details['reason'],
            'requested_in_advance'      => $this->inAdvance(),
            'status'                    => 'pending',
            'requested_by'              => $user->id,
        ]);
    }

    public function cancel(OvertimeRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw new UserFacingException('Only a pending request can be withdrawn.');
        }
        // Requested with other dates: they're approved together, so they're withdrawn together.
        if ($request->group) {
            DB::transaction(function () use ($request) {
                if ($request->group->approval?->isPending()) {
                    $this->approvals->cancel($request->group->approval);
                }
                $request->group->update(['status' => Approval::CANCELLED]);
                $request->group->requests()->where('status', 'pending')->update(['status' => Approval::CANCELLED]);
            });

            return;
        }
        DB::transaction(function () use ($request) {
            if ($request->approval) {
                $this->approvals->cancel($request->approval);
            }
            $request->update(['status' => Approval::CANCELLED]);
        });
    }

    /**
     * Confirm the hours actually worked on overtime approved in advance (by the employee, HR, or later a
     * timesheet). Paid by the pay basis once confirmed; a run being prepared needs recalculating.
     */
    public function confirmActual(OvertimeRequest $request, float $hours): OvertimeRequest
    {
        if ($request->status !== 'approved' || !$request->requested_in_advance) {
            throw new UserFacingException('Hours worked are confirmed on overtime approved in advance.');
        }
        if ($request->work_date->isFuture()) {
            throw new UserFacingException('Confirm the hours once the work is done (' . $request->work_date->format('j M Y') . ').');
        }
        if ($request->payRun && !$request->payRun->status->isOpen()) {
            throw new UserFacingException("This overtime is in {$request->payRun->name}, which has been sent for approval, so it can't change.");
        }
        $perDay = (float) setting('payroll.overtime_max_hours_per_day', 12);
        if ($hours < 0 || $hours > $perDay) {
            throw new UserFacingException("Enter the hours worked: 0 to {$perDay}.");
        }

        DB::transaction(function () use ($request, $hours) {
            if ($request->payRun?->status === \App\Enums\Payroll\PayRunStatus::CALCULATED) {
                $request->payRun->update(['status' => \App\Enums\Payroll\PayRunStatus::DRAFT]);
            }
            $request->update(['actual_hours' => round($hours, 2)]);
        });

        return $request->fresh();
    }

    /**
     * Bring approved, unpaid overtime worked up to the end of the run's period into a regular run, as
     * one input per employee and overtime component. Recalculating does it again from scratch. Overtime
     * approved in advance and still waiting for its hours worked stays out.
     *
     * @return array<int, string[]> per employee, notes about overtime left waiting (for their payslip)
     */
    public function claimFor(PayRun $run): array
    {
        return DB::transaction(function () use ($run) {
            $this->release($run);
            if ($run->type !== 'regular' || !feature('payroll.overtime')) {
                return [];
            }

            $paid = EmployeePayProfile::inForceOn($run->period_end)->pluck('employee_id');
            $requests = OvertimeRequest::where('status', 'approved')->whereNull('pay_run_id')
                ->whereDate('work_date', '<=', $run->period_end)
                ->whereIn('employee_id', $paid)
                ->with('type', 'addonType')->get();

            $waiting = [];
            $payable = $requests->filter(function (OvertimeRequest $r) use (&$waiting) {
                if ($r->payableHours() !== null) {
                    return true;
                }
                $waiting[$r->employee_id][] = 'Overtime on ' . $r->work_date->format('j M') . ' (' . (float) $r->approved_hours . ' h approved) is waiting for the hours worked to be confirmed, so it isn\'t paid yet.';

                return false;
            });

            // A line per employee and component: the day's type, and any type paid on top (e.g. offshore) for the same hours.
            $lines = $payable->flatMap(fn ($r) => array_filter([[$r, $r->type->pay_component_id], $r->addonType ? [$r, $r->addonType->pay_component_id] : null]));
            foreach ($lines->groupBy(fn ($l) => $l[0]->employee_id . ':' . $l[1]) as $pairs) {
                $group = $pairs->pluck(0);
                $first = $group->first();
                PayRunInput::create([
                    'pay_run_id'       => $run->id,
                    'employee_id'      => $first->employee_id,
                    'pay_component_id' => $pairs->first()[1],
                    'quantity'         => $group->sum(fn ($r) => $r->payableHours()),
                    'source'           => 'overtime',
                    'notes'            => $group->count() . ' approved request' . ($group->count() > 1 ? 's' : '') . ', ' . $group->min('work_date')->format('j M') . ($group->count() > 1 ? ' – ' . $group->max('work_date')->format('j M') : ''),
                ]);
            }
            OvertimeRequest::whereIn('id', $payable->pluck('id'))->update(['pay_run_id' => $run->id]);

            return $waiting;
        });
    }

    /** Hand a run's overtime back (the run is recalculated or removed). */
    public function release(PayRun $run): void
    {
        PayRunInput::where('pay_run_id', $run->id)->where('source', 'overtime')->delete();
        OvertimeRequest::where('pay_run_id', $run->id)->update(['pay_run_id' => null]);
    }
}
