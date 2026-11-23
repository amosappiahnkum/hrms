<?php

namespace App\Services\Leave;

use App\Models\Config\LeaveTypeLevelConfig;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveRequest;
use App\Models\SelfService\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What an employee has left of a leave type in a leave cycle, and the rules a new request must meet.
 *
 * The cycle is a year starting on setting `leave.cycle_start` (MM-DD, default 01-01). A cycle's entitlement is the
 * category's `number_of_days`, plus balance adjustments made in it, plus (when `allow_carry_forward`) what was left of
 * the previous cycle, capped at `max_carry_forward_days`. Carried days don't carry again. Leave counts against the cycle
 * its first day falls in. Leave types counted in years (`request_type` years) have no cycle: their balance is lifetime.
 */
class LeaveBalance
{
    /** [start, end] (inclusive dates) of the leave cycle that contains $on. */
    public static function cycle(?CarbonInterface $on = null): array
    {
        $on = CarbonImmutable::parse($on ?? now())->startOfDay();
        [$month, $day] = self::cycleStart();

        $start = $on->setDate($on->year, $month, min($day, CarbonImmutable::create($on->year, $month)->daysInMonth));
        if ($start->greaterThan($on)) {
            $start = $start->subYearNoOverflow();
        }

        return [$start, $start->addYearNoOverflow()->subDay()];
    }

    /** [month, day] the leave cycle starts on. */
    public static function cycleStart(): array
    {
        $value = (string) setting('leave.cycle_start', '01-01');
        if (!preg_match('/^(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[1], (int) $m[2], 2024)) {
            return [1, 1];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    public function configFor(Employee $employee, int $leaveTypeId): ?LeaveTypeLevelConfig
    {
        $categoryId = $employee->jobDetail?->job_category_id;

        return $categoryId ? LeaveTypeLevelConfig::with('leaveType')
            ->where('job_category_id', $categoryId)
            ->where('leave_type_id', $leaveTypeId)
            ->first() : null;
    }

    /** The balance of the cycle that contains $on (default today). */
    public function for(Employee $employee, LeaveTypeLevelConfig $config, ?CarbonInterface $on = null): array
    {
        $config->loadMissing('leaveType');
        $lifetime = $config->leaveType->request_type === 'years';
        [$start, $end] = $lifetime ? [null, null] : self::cycle($on);

        $entitlement = (float) $config->number_of_days;
        $adjustment = $this->adjustments($employee, $config->leave_type_id, $start, $end);
        $carried = $lifetime ? 0.0 : $this->carriedForward($employee, $config, $start);
        $used = $this->used($employee, $config->leave_type_id, $start, $end);
        $pending = $this->pending($employee, $config->leave_type_id, $start, $end);

        $total = $entitlement + $adjustment + $carried;

        return [
            'leave_type_id' => $config->leave_type_id,
            'type' => $config->leaveType->name,
            'cycle_start' => $start?->toDateString(),
            'cycle_end' => $end?->toDateString(),
            'entitlement' => $entitlement,
            'adjustment' => $adjustment,
            'carried_forward' => $carried,
            'total' => $total,
            'used' => $used,
            'pending' => $pending,
            'remaining' => max(0, $total - $used),
            'available' => max(0, $total - $used - $pending),
            'allow_half_day' => (bool) $config->allow_half_day,
            'maximum_consecutive_days' => $config->maximum_consecutive_days ?: null,
            'should_request_before' => $config->should_request_before ?: null,
        ];
    }

    /**
     * Why a request for $days starting $startDate breaks this category's rules, or null when it doesn't.
     * The balance is checked against the cycle the leave starts in.
     */
    public function violation(Employee $employee, LeaveTypeLevelConfig $config, CarbonInterface $startDate, float $days): ?string
    {
        $config->loadMissing('leaveType');
        $type = $config->leaveType;
        $unit = $type->request_type === 'years' ? 'year' : 'day';

        if ($days <= 0) {
            return "Request at least half a {$unit}.";
        }
        $whole = floor($days) == $days;
        if (!$whole && ($unit === 'year' || !$config->allow_half_day)) {
            return "{$type->name} must be taken in whole {$unit}s.";
        }
        if (!$whole && fmod($days * 2, 1.0) != 0.0) {
            return 'Leave can only be taken in whole or half days.';
        }

        if ($unit === 'day' && $config->maximum_consecutive_days > 0 && $days > $config->maximum_consecutive_days) {
            return "You can take at most {$config->maximum_consecutive_days} days of {$type->name} in one request.";
        }

        if ($config->should_request_before > 0) {
            $earliest = now()->startOfDay()->addDays((int) $config->should_request_before);
            if (CarbonImmutable::parse($startDate)->startOfDay()->lessThan($earliest)) {
                return "{$type->name} must be requested at least {$config->should_request_before} day(s) ahead: the earliest start is {$earliest->format('D, M d Y')}.";
            }
        }

        $balance = $this->for($employee, $config, $startDate);
        if ($balance['available'] < $days) {
            $cycle = $balance['cycle_start'] ? " in the leave year starting {$balance['cycle_start']}" : '';
            return "Insufficient leave balance. You have {$balance['available']} {$unit}(s) of {$type->name} available{$cycle}.";
        }

        return null;
    }

    /** What was left of the previous cycle, capped at max_carry_forward_days (unset = no cap). */
    private function carriedForward(Employee $employee, LeaveTypeLevelConfig $config, CarbonImmutable $cycleStart): float
    {
        if (!$config->allow_carry_forward) {
            return 0.0;
        }

        $prevStart = $cycleStart->subYearNoOverflow();
        $prevEnd = $cycleStart->subDay();

        // Nothing to carry from a cycle before they were employed.
        $hired = $employee->jobDetail?->joined_date;
        if ($hired && CarbonImmutable::parse($hired)->greaterThan($prevEnd)) {
            return 0.0;
        }

        $left = (float) $config->number_of_days
            + $this->adjustments($employee, $config->leave_type_id, $prevStart, $prevEnd)
            - $this->used($employee, $config->leave_type_id, $prevStart, $prevEnd);
        $left = max(0.0, $left);

        return $config->max_carry_forward_days !== null ? min($left, (float) $config->max_carry_forward_days) : $left;
    }

    private function adjustments(Employee $employee, int $leaveTypeId, ?CarbonImmutable $start, ?CarbonImmutable $end): float
    {
        return (float) LeaveBalanceAdjustment::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveTypeId)
            ->when($start, fn ($q) => $q->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()]))
            ->sum('days');
    }

    private function used(Employee $employee, int $leaveTypeId, ?CarbonImmutable $start, ?CarbonImmutable $end): float
    {
        return (float) LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', 'hr_approved')
            ->when($start, fn ($q) => $q->whereBetween('start_date', [$start->toDateString(), $end->toDateString()]))
            ->sum('days_approved');
    }

    private function pending(Employee $employee, int $leaveTypeId, ?CarbonImmutable $start, ?CarbonImmutable $end): float
    {
        return (float) LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveTypeId)
            ->whereIn('status', ['pending', 'hod_approved'])
            ->when($start, fn ($q) => $q->whereBetween('start_date', [$start->toDateString(), $end->toDateString()]))
            ->sum('days_requested');
    }
}
