<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\LeaveResumption;
use App\Models\User;
use App\Notifications\ResumptionAcknowledgedNotification;
use App\Notifications\ResumptionConfirmedNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class LeaveResumptionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Employee: check if they have a leave that ended without a confirmed resumption.
     */
    public function checkPending(): JsonResponse
    {
        $employee = Auth::user()->employee;

        if (!$employee) {
            return response()->json(['uuid' => null]);
        }

        $pendingLeave = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['hr_approved'])
            ->whereDate('end_date', '<=', Carbon::today())
            ->where(function ($q) {
                $q->whereDoesntHave('resumption')
                    ->orWhereHas('resumption', fn($q) => $q->where('status', '!=', 'completed'));
            })
            ->with(['leaveType', 'resumption'])
            ->latest('end_date')
            ->first();

        if (!$pendingLeave) {
            return response()->json(['uuid' => null]);
        }

        return response()->json([
            'uuid' => $pendingLeave->uuid,
            'leave_type' => $pendingLeave->leaveType?->name,
            'end_date' => $pendingLeave->end_date,
            'resumption_status' => $pendingLeave->resumption?->status ?? 'not_confirmed',
        ]);
    }

    /**
     * Employee: confirm resumption of duty.
     */
    public function confirm(string $uuid): JsonResponse
    {
        $employee = Auth::user()->employee;

        $leaveRequest = LeaveRequest::where('uuid', $uuid)
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['hr_approved'])
            ->whereDate('end_date', '<', Carbon::today())
            ->whereDoesntHave('resumption')
            ->with(['leaveType', 'employee.department'])
            ->firstOrFail();

        $hod = $employee->department->headOfDepartment;

        if (!$hod) {
            return response()->json(['message' => 'No HOD assigned to your department.'], 400);
        }

        $leaveRequest->resumption()->create([
            'employee_confirmed_at' => Carbon::now(),
            'status' => 'pending_hod',
        ]);

        $resumeDate = Carbon::parse($leaveRequest->end_date)->addDay()->format('d/m/Y');
        $leaveType = $leaveRequest->leaveType->name;
        $department = $employee->department->name;

        $hod->userAccount?->notify(new ResumptionConfirmedNotification([
            'subject' => 'Resumption of Duty Notification',
            'greeting' => "Dear {$hod->first_name}!",
            'lines' => [
                "I, {$employee->name} of {$department}, wish to notify you of my resumption to duty from my {$leaveType} on this date {$resumeDate}.",
                "Please acknowledge receipt of this notification.",
            ],
        ]));

        return response()->json(['message' => 'Resumption confirmed. Your HOD has been notified.']);
    }

    /**
     * HOD: get pending resumption acknowledgements for their department.
     */
    public function pendingAcknowledgements(): JsonResponse
    {
        $hod = Auth::user()->employee;

        if (!$hod) {
            return response()->json([]);
        }

        $resumptions = LeaveResumption::where('status', 'pending_hod')
            ->whereHas('leaveRequest', fn($q) => $q->where('department_id', $hod->department_id))
            ->with(['leaveRequest.employee', 'leaveRequest.leaveType'])
            ->get()
            ->map(fn($r) => [
                'uuid' => $r->uuid,
                'employee_name' => $r->leaveRequest->employee->name,
                'department_name' => $r->leaveRequest->employee->department->name,
                'leave_type' => $r->leaveRequest->leaveType->name,
                'end_date' => $r->leaveRequest->end_date,
                'employee_confirmed_at' => $r->employee_confirmed_at,
            ]);

        return response()->json($resumptions);
    }

    /**
     * HOD: acknowledge an employee's resumption.
     */
    public function acknowledge(string $uuid): JsonResponse
    {
        $hod = Auth::user()->employee;

        $resumption = LeaveResumption::where('uuid', $uuid)
            ->where('status', 'pending_hod')
            ->with(['leaveRequest.employee.department', 'leaveRequest.employee.contactDetail',
                'leaveRequest.employee.userAccount', 'leaveRequest.leaveType'])
            ->firstOrFail();

        $leaveRequest = $resumption->leaveRequest;

        if ($leaveRequest->department_id !== $hod->department_id) {
            return response()->json(['message' => 'You can only acknowledge resumptions for your own department.'], 403);
        }

        $resumption->update([
            'hod_id' => $hod->id,
            'hod_acknowledged_at' => Carbon::now(),
            'status' => 'completed',
        ]);

        $employee = $leaveRequest->employee;
        $leaveType = $leaveRequest->leaveType->name;
        $resumeDate = Carbon::parse($leaveRequest->end_date)->addDay()->format('d/m/Y');

        // Notify employee
        $employee->userAccount?->notify(new ResumptionAcknowledgedNotification([
            'subject' => 'Your Resumption Has Been Acknowledged',
            'greeting' => "Dear {$employee->first_name},",
            'lines' => [
                "Your resumption from {$leaveType} effective {$resumeDate} has been acknowledged by {$hod->name}.",
                "This has also been communicated to HR.",
            ],
        ]));

        // Notify HOD (confirmation of their own action)
        Auth::user()->notify(new ResumptionAcknowledgedNotification([
            'subject' => 'Resumption Acknowledgement Confirmed',
            'greeting' => "Dear {$hod->first_name},",
            'lines' => [
                "You have successfully acknowledged {$employee->name}'s resumption from {$leaveType} effective {$resumeDate}.",
                "HR has been notified.",
            ],
        ]));

        // Notify HR — one email to the first HR user, rest in CC
        $hrUsers = User::role('hr')->get();
        $primaryHr = $hrUsers->first();

        if ($primaryHr) {
            $cc = $hrUsers->skip(1)->pluck('email')->filter()->values()->all();

            $primaryHr->notify(new ResumptionAcknowledgedNotification([
                'subject'  => 'Resumption of Duty Acknowledged',
                'greeting' => 'Dear HR Team,',
                'cc'       => $cc,
                'lines'    => [
                    "{$employee->name} of {$employee->department->name} has resumed duty from {$leaveType} effective {$resumeDate}.",
                    "This resumption has been acknowledged by {$hod->name}.",
                ],
            ]));
        }

        return response()->json(['message' => 'Resumption acknowledged. HR has been notified.']);
    }
}
