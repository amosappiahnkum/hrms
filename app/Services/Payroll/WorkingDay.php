<?php

namespace App\Services\Payroll;

/**
 * The normal working day (settings `payroll.work_day_start`, `work_day_end`, `work_day_break_minutes`), e.g.
 * 08:00–17:00 with an hour's break = 8 hours. On a weekday, hours past it are overtime; with start and end times,
 * so are hours outside it. `payroll.hours_per_day` (the overtime hourly rate) follows from it.
 */
class WorkingDay
{
    /** Minutes after midnight the day starts. */
    public static function start(): int
    {
        return self::minutes((string) setting('payroll.work_day_start', '08:00')) ?? 480;
    }

    public static function end(): int
    {
        return self::minutes((string) setting('payroll.work_day_end', '17:00')) ?? 1020;
    }

    public static function breakMinutes(): int
    {
        return max(0, (int) setting('payroll.work_day_break_minutes', 60));
    }

    /** Hours in a normal day. */
    public static function hours(): float
    {
        return self::hoursFor(self::start(), self::end(), self::breakMinutes());
    }

    public static function hoursFor(int $start, int $end, int $break): float
    {
        return max(0.0, round(($end - $start - $break) / 60, 2));
    }

    /** "08:30" → 510; null when it isn't a time. */
    public static function minutes(?string $time): ?int
    {
        if (!$time || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:\d\d)?$/', trim($time), $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** Which kinds of day need overtime approved before the work (when overtime is requested in advance). */
    public static function advanceKinds(): array
    {
        return array_values(array_intersect(['weekday', 'weekend', 'holiday'], (array) setting('payroll.overtime_advance_for', ['weekday', 'weekend', 'holiday'])));
    }
}
