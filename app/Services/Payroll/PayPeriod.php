<?php

namespace App\Services\Payroll;

use App\Models\Holiday;
use Illuminate\Support\Carbon;

/**
 * A pay month and the dates it covers. With the start day at 1 that is the calendar month; with, say, 21
 * the September period runs 21 August – 20 September (setting `payroll.period_start_day`).
 */
final class PayPeriod
{
    private ?int $workingDays = null;

    private ?array $holidays = null;

    private function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {
    }

    /** The organization's period start day, 1–28. */
    public static function startDay(): int
    {
        return max(1, min(28, (int) setting('payroll.period_start_day', 1)));
    }

    public static function forMonth(int $year, int $month, ?int $startDay = null): self
    {
        $startDay ??= self::startDay();
        $first = Carbon::create($year, $month, 1)->startOfDay();

        if ($startDay === 1) {
            return new self($year, $month, $first, $first->copy()->endOfMonth()->startOfDay());
        }

        return new self($year, $month, $first->copy()->subMonthNoOverflow()->day($startDay), $first->copy()->day($startDay - 1));
    }

    /** A run's period as it was created (the start day may have changed since). */
    public static function ofRun(\App\Models\Payroll\PayRun $run): self
    {
        return new self($run->year, $run->month, $run->period_start->copy()->startOfDay(), $run->period_end->copy()->startOfDay());
    }

    /** A timesheet's period as it was made. */
    public static function ofSheet(\App\Models\Payroll\Timesheet $sheet): self
    {
        return new self($sheet->year, $sheet->month, $sheet->period_start->copy()->startOfDay(), $sheet->period_end->copy()->startOfDay());
    }

    /** The pay period a date falls in: from the start day on, it belongs to the next month's period. */
    public static function containing(Carbon $date, ?int $startDay = null): self
    {
        $startDay ??= self::startDay();
        $month = $date->copy()->startOfMonth();
        if ($startDay > 1 && $date->day >= $startDay) {
            $month->addMonthNoOverflow();
        }

        return self::forMonth($month->year, $month->month, $startDay);
    }

    /** Public holidays in the period: date (Y-m-d) => description. */
    public function holidays(): array
    {
        if ($this->holidays !== null) {
            return $this->holidays;
        }

        $holidays = [];
        Holiday::whereDate('start_date', '<=', $this->end)
            ->where(fn ($q) => $q->whereDate('end_date', '>=', $this->start)->orWhere(fn ($w) => $w->whereNull('end_date')->whereDate('start_date', '>=', $this->start)))
            ->get(['description', 'start_date', 'end_date'])
            ->each(function ($h) use (&$holidays) {
                $day = Carbon::parse($h->start_date)->startOfDay();
                $last = $h->end_date ? Carbon::parse($h->end_date)->startOfDay() : $day->copy();
                for (; $day->lte($last); $day->addDay()) {
                    $holidays[$day->toDateString()] = (string) $h->description;
                }
            });

        return $this->holidays = $holidays;
    }

    /** weekday | weekend | holiday, for a date in the period (as overtime types use them). */
    public function dayKind(Carbon $date): string
    {
        return isset($this->holidays()[$date->toDateString()]) ? 'holiday' : ($date->isWeekend() ? 'weekend' : 'weekday');
    }

    /** Weekdays in the period that aren't public holidays. */
    public function workingDays(): int
    {
        if ($this->workingDays !== null) {
            return $this->workingDays;
        }

        $holidays = $this->holidays();
        $count = 0;
        for ($day = $this->start->copy(); $day->lte($this->end); $day->addDay()) {
            if (!$day->isWeekend() && !isset($holidays[$day->toDateString()])) {
                $count++;
            }
        }

        return $this->workingDays = $count;
    }

    /** "September 2026", or "September 2026 (21 Aug – 20 Sep)" when it isn't the calendar month. */
    public function label(): string
    {
        $name = Carbon::create($this->year, $this->month, 1)->format('F Y');

        return $this->isCalendarMonth() ? $name : "{$name} ({$this->start->format('j M')} – {$this->end->format('j M')})";
    }

    public function isCalendarMonth(): bool
    {
        return $this->start->day === 1 && $this->end->isSameDay($this->start->copy()->endOfMonth());
    }
}
