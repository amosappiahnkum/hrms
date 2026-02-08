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
        $users = User::role('hr')->get();

        foreach ($users as $item) {
            if (!empty($item)) {
                $emp = $item->employee;
                $data['greeting'] = "Dear $emp->first_name";
                $data['subject'] = 'Time off Request';
                $item->notify(new LeaveStatusHrNotification($data));
            }
        }
    }

    public function validateLeaveDays($startDate, $daysRequested)
    {
        $daysCount = $this->getLeaveDays($startDate, $daysRequested);

        if ($daysCount < $daysRequested) {
            $daysRequested = $this->getLeaveDays($startDate, $daysRequested + ($daysRequested - $daysCount));
        }

        return $daysRequested;
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
