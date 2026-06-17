<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use App\Notifications\LeaveResumptionReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendLeaveResumptionReminders extends Command
{
    protected $signature = 'leave:send-resumption-reminders';

    protected $description = 'Notify employees and their HOD one day before leave ends (resumption reminder)';

    public function handle(): void
    {
        $tomorrow = Carbon::tomorrow()->toDateString();

        $leaveRequests = LeaveRequest::with([
            'employee.contactDetail',
            'employee.userAccount',
            'approver.contactDetail',
            'approver.userAccount',
            'leaveType',
        ])
            ->whereIn('status', ['hr_approved'])
            ->whereDate('end_date', $tomorrow)
            ->get();

        if ($leaveRequests->isEmpty()) {
            $this->info('No resumptions due tomorrow.');
            return;
        }

        foreach ($leaveRequests as $leaveRequest) {
            $employee   = $leaveRequest->employee;
            $supervisor = $leaveRequest->approver;
            $leaveType  = $leaveRequest->leaveType->name ?? 'leave';
            $resumeDate = Carbon::parse($leaveRequest->end_date)->addDay()->format('l, F j, Y');
            $endDate    = Carbon::parse($leaveRequest->end_date)->format('l, F j, Y');

            // Notify employee
            $employeeAccount = $employee?->userAccount;
            if ($employeeAccount) {
                $employeeAccount->notify(new LeaveResumptionReminderNotification([
                    'subject' => 'Resumption Reminder',
                    'greeting' => "Dear {$employee->first_name}!",
                    'lines' => [
                        "This is a reminder that your {$leaveType} ends on {$endDate}.",
                        "You are expected to resume work on {$resumeDate}.",
                        "Please ensure you are prepared for your return.",
                    ],
                ]));
            }

            // Notify HOD
            $hodAccount = $supervisor?->userAccount;
            if ($hodAccount) {
                $hodAccount->notify(new LeaveResumptionReminderNotification([
                    'subject' => 'Staff Resumption Reminder',
                    'greeting' => "Dear {$supervisor->first_name}!",
                    'lines' => [
                        "This is a reminder that {$employee->name} is due to resume from {$leaveType} on {$resumeDate}.",
                        "Their leave period ends on {$endDate}.",
                    ],
                ]));
            }

            $this->info("Reminders sent for: {$employee->name} (resumes {$resumeDate})");
        }

        Log::info('SendLeaveResumptionReminders: processed ' . $leaveRequests->count() . ' leave(s) for ' . $tomorrow);
    }
}
