<?php

namespace App\Helpers;

use App\Models\Holiday;
use App\Models\User;
use App\Notifications\hr\LeaveStatusHrNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LeaveHelper
{
    public string $lastDate = '';

    public function notifyAllHrs($data): void
    {
        $users = User::permission('finalize-leave')->get();

        foreach ($users as $item) {
            if (!empty($item)) {
                $emp = $item->employee;
                $data['greeting'] = "Dear $emp->first_name";
                $data['subject'] = 'Time off Request';
                $item->notify(new LeaveStatusHrNotification($data));
            }
        }
    }

    public function validateLeaveDays($startDate, $daysRequested, $type = "days")
    {
        if ($type == "years") {
            $this->lastDate = Carbon::parse($startDate)->addYears($daysRequested)->format('Y-m-d');

            return $daysRequested;
        }

        // A half day takes up its calendar day: dates are worked out on whole days, the half is kept.
        $requested = (float) $daysRequested;
        $whole = (int) ceil($requested);
        $half = $requested - $whole;

        $daysCount = $this->getLeaveDays($startDate, $whole);

        if ($daysCount < $whole) {
            $whole = $this->getLeaveDays($startDate, $whole + ($whole - $daysCount));
        }

        return $whole + $half;
    }

    /**
     * @param $startDate
     * @param $numberOfDays
     *
     * @return int
     */
    public function getLeaveDays($startDate, $numberOfDays): int
    {
        $holidays = $this->getHolidays()->pluck('start_date');
        $start = Carbon::parse($startDate)->startOfDay();
        $endDate = Carbon::parse($startDate)->addWeekdays($numberOfDays)->startOfDay();


        return $start->diffInDaysFiltered(function (Carbon $date) use ($holidays) {
            $check = $date->isWeekday() && !$holidays->contains($date->format('Y-m-d'));

            if ($check) {
                $this->lastDate = $date->format('Y-m-d');
            }

            return $check;
        }, $endDate);
    }

    public function getHolidays(): Collection
    {
        return Holiday::query()->whereYear('start_date', date('Y'))->orderBy('start_date')->get();
    }
}
