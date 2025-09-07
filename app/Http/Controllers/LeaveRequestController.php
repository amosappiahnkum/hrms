<?php

namespace App\Http\Controllers;

use App\Http\Requests\HrChangeLeaveStatusRequest;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeSupervisor;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\hr\LeaveStatusHrNotification;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\LeaveStatusNotification;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class LeaveRequestController extends Controller
{
    private string $lastDate = '';

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $leaveRequestQuery = LeaveRequest::query();

        $leaveRequestQuery->when($request->has('status'), function ($q) use ($request) {
            return $q->where('status', $request->status)
                ->where('employee_id', Auth::user()->employee->id)
                ->orWhere('hr_status', $request->status)
                ->where('employee_id', Auth::user()->employee->id);
        });

        $leaveRequestQuery->where('employee_id', Auth::user()->employee->id);

        return LeaveRequestResource::collection($leaveRequestQuery->paginate(10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreLeaveRequestRequest $request
     *
     * @return JsonResponse
     */
    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $startDate = Carbon::parse($request->start_date)->format('Y-m-d');
            $daysRequested = $request->number_of_days;

            $this->validateLeaveDays($startDate, $daysRequested);

            $employee = Auth::user()->employee;

            $leaveRequest = LeaveRequest::create([
                'employee_id' => $employee->id,
                'supervisor_id' => $request->employee_id,
                'leave_type_id' => $request->leave_type_id,
                'days_requested' => $daysRequested,
                'start_date' => $startDate,
                'reason' => $request->reason,
                'end_date' => $this->lastDate,
            ]);

            $supervisor = Employee::find($request->employee_id);

            EmployeeSupervisor::updateOrCreate([
                'supervisor_id' => $request->employee_id,
                'employee_id' => $employee->id,
            ], [
                'supervisor_id' => $request->employee_id,
                'employee_id' => $employee->id,
            ]);

            Notification::route('mail', $supervisor->contactDetail->work_email)
                ->notify(new LeaveRequestNotification([
                    'supervisor' => $supervisor->first_name,
                    'employee' => $employee->name,
                    'startDate' => Carbon::parse($startDate)->format('l, M d Y'),
                    'endDate' => Carbon::parse($this->lastDate)->format('l, M d Y'),
                    'daysRequested' => $daysRequested
                ]));

            DB::commit();

            return response()->json(new LeaveRequestResource($leaveRequest));
        } catch (Exception $exception) {
            DB::rollBack();

            Log::error('Leave Request Error', [$exception]);

            return response()->json('Something went wrong', 400);
        }
    }

    private function validateLeaveDays($startDate, $daysRequested)
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
        $holidays = $this->getHolidays();
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

    /**
     * @return Collection
     */
    public function getHolidays(): Collection
    {
        return Holiday::all()->pluck('start_date');
    }

    /**
     * @return Collection
     */
    public function getLeaveTypes(): Collection
    {
        return LeaveType::all();
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function changeLeaveStatus(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $leaveRequest = LeaveRequest::find($request->id);

            $date = Carbon::now()->format('Y-m-d');;
            $daysApproved =
                $request->days_requested != $leaveRequest->days_requested ?
                    $this->validateLeaveDays($request->start_date, $request->days_requested) : $request->days_requested;

            $leaveRequest->update([
                'days_approved' => $daysApproved,
                'start_date' => Carbon::parse($request->start_date)->format('Y-m-d'),
                'status' => $request->status,
                'sup_reason' => $request->sup_reason,
                'end_date' => $this->lastDate ?: $leaveRequest->end_date,
                'sup_approval' => $date,
                'viewed' => true,
            ]);

            $employeeUserAccount = $leaveRequest->employee->userAccount;

            $user = Auth::user();

            if ($user) {
                // notify supervisor
//                $user->notify(new LeaveStatusNotification([
//                    'leaveStatus' => $request->status,
//                    'supervisor' => $user->employee->name,
//                    'employee' => $employeeUserAccount->employee->name,
//                    'date' => $date
//                ], true, $request->status === 'approved'));

                // notify employee
                $employeeUserAccount->notify(new LeaveStatusNotification([
                    'leaveStatus' => $request->status,
                    'supervisor' => $user->employee->name,
                    'employee' => $employeeUserAccount->employee->name,
                    'date' => $date
                ]));
            }

            if ($request->status === 'approved') {
                // notify HRs
                $users = User::permission('move-leave')->get();

                foreach ($users as $item) {
                    if (!empty($item)) {
                        $item->notify(new LeaveStatusHrNotification([
                            'hr' => $item->employee?->name,
                            'supervisor' => $user->employee->name,
                            'employee' => $employeeUserAccount->employee->name,
                            'date' => $date,
                            'leaveStatus' => $request->status
                        ]));
                    }
                }
            }

            ActivityLog::add(
                $user->employee->name . ' ' .
                $request->status . ' ' . $daysApproved .
                ' day(s) leave request starting from ' . $request->start_date . ' to ' . $this->lastDate,
                $request->status, [''], 'leave-request')
                ->to($leaveRequest)
                ->as($user);

            DB::commit();

            return response()->json(new LeaveRequestResource($leaveRequest));
        } catch (Exception $exception) {
            DB::rollBack();
            Log::error('Change Leave Status: ', [$exception]);

            return response()->json('Something went wrong', 400);
        }
    }

    public function hrChangeLeaveStatus(HrChangeLeaveStatusRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $leaveRequest = LeaveRequest::find($request->id);

            $daysApproved = $this->validateLeaveDays($request->start_date, $request->days_requested);


            if ($leaveRequest->moved == null) {
                $this->moveLeaveForApproval($leaveRequest,
                    Carbon::parse($request->start_date)->format('Y-m-d'), $daysApproved);
            } else {
                $user = Auth::user();

                $leaveRequest->update([
                    'days_approved' => $daysApproved,
                    'start_date' => Carbon::parse($request->start_date)->format('Y-m-d'),
                    'end_date' => $this->lastDate,
                    'hr_reason' => $request->hr_reason,
                    'hr_status' => $request->hr_status_update,
                    'hr_approval' => date('Y-m-d'),
                    'hr_id' => $user?->employee->id
                ]);

                ActivityLog::add($user->employee->name . ' ' . $request->hr_status_update . ' leave request',
                    $request->hr_status_update, [''], 'leave-request')
                    ->to($leaveRequest)
                    ->as($user);

                $date = Carbon::now()->format('Y-m-d');

                $employeeUserAccount = $leaveRequest->employee->userAccount;

                $employeeUserAccount->notify(new LeaveStatusNotification([
                    'leaveStatus' => $request->hr_status_update,
                    'supervisor' => $employeeUserAccount->employee->name,
                    'employee' => $user->employee->name,
                    'date' => $date,
                    'hasPendingText' => false
                ]));
            }

            DB::commit();

            return response()->json(new LeaveRequestResource($leaveRequest));
        } catch (Exception $exception) {
            DB::rollBack();
            Log::error('HR Change Leave Status: ', [$exception]);

            return response()->json('Something went wrong', 400);
        }
    }

    public function moveLeaveForApproval(LeaveRequest $leaveRequest, $startDate, $approvedDays)
    {
        $leaveRequest->update([
            'moved' => Carbon::now()->format('Y-m-d'),
            'start_date' => $startDate,
            'days_approved' => $approvedDays,
            'moved_by' => Auth::user()->employee->id
        ]);

        $user = Auth::user();
        ActivityLog::add($user->employee->name . ' Moved leave request',
            'Moved', [''], 'leave-request')
            ->to($leaveRequest)
            ->as($user);

        $users = User::permission('approve-leave')->get();

        foreach ($users as $item) {
            if (!empty($item)) {
                $item->notify(new LeaveStatusHrNotification([
                    'hr' => $item->employee?->name,
                    'supervisor' => $leaveRequest->employee->name,
                    'employee' => $user?->employee->name,
                    'date' => Carbon::now()->format('Y-m-d'),
                    'leaveStatus' => 'Moved'
                ]));
            }
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param LeaveRequest $leaveRequest
     *
     * @return Response
     */
    public function destroy(LeaveRequest $leaveRequest)
    {
        //
    }

    public function show(LeaveRequest $leaveRequest): JsonResponse
    {
        return response()->json(new LeaveRequestResource($leaveRequest));
    }

    public function getMyLeaveRequest(Request $request): AnonymousResourceCollection
    {
        $auth = Auth::user();

        $leaveRequest = LeaveRequest::query();

        $leaveRequest->where('employee_id', $auth->employee->id);

        return LeaveRequestResource::collection($leaveRequest->paginate($request->per_page ?? 10));
    }
}
