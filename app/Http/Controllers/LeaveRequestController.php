<?php

namespace App\Http\Controllers;

use App\Http\Requests\HrChangeLeaveStatusRequest;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Resources\UpcomingLeaveResource;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeSupervisor;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveTypeLevelConfig;
use App\Models\User;
use App\Notifications\hr\LeaveStatusHrNotification;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\LeaveStatusNotification;
use App\Notifications\NotifyHodNotification;
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
use Illuminate\Support\Facades\Schema;

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
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if (!$this->isHrAdmin()) {
            return response()->json([
                'message' => 'You do not have permission to view leave requests.'
            ], 403);
        }

        $leaveRequestQuery = LeaveRequest::query();

        $leaveRequestQuery->when($request->has('status'), function ($q) use ($request) {
            return $q->where('status', $request->status);
        });
        $leaveRequestQuery->when($request->has('department'), function ($q) use ($request) {
            return $q->where('department_id', $request->department);
        });

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

            $leaveType = LeaveType::where('uuid', $request->leave_type_id)->first();

            $department = $employee->department;

            $leaveRequest = LeaveRequest::create([
                'employee_id' => $employee->id,
                'supervisor_id' => $department->hod,
                'department_id' => $department->id,
                'leave_type_id' => $leaveType->id,
                'days_requested' => $daysRequested,
                'start_date' => $startDate,
                'reason' => $request->reason,
                'end_date' => $this->lastDate,
            ]);

            $supervisor = Employee::find($department->hod);

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

    /**
     * @return Collection
     */
    public function getHolidays(): Collection
    {
        return Holiday::query()->whereYear('start_date', date('Y'))->orderBy('start_date')->get();
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
            $leaveRequest = LeaveRequest::where('uuid', $request->id)->first();

            $date = Carbon::now()->format('Y-m-d');;
            $daysApproved =
                $request->days_requested != $leaveRequest->days_requested ?
                    $this->validateLeaveDays($request->start_date, $request->days_requested) : $request->days_requested;

            $decision = $request->decision;

            $daysApproved =  $decision == 'approved' ? $daysApproved : 0;
            $leaveRequest->update([
                'days_approved' => $daysApproved,
                'start_date' => Carbon::parse($request->start_date)->format('Y-m-d'),
                'status' => $request->status,
                'sup_reason' => $request->sup_reason,
                'end_date' => $this->lastDate ?: $leaveRequest->end_date,
                'sup_approval' => $date,
                'viewed' => true,
            ]);

            $hod = Auth::user();

            $leaveRequest->approvals()->create([
                'approved_by' => $hod->id,
                'role' => 'hod',
                'decision' => $decision,
                'comment' => $request->sup_reason,
                'decided_at' => Carbon::now(),
                'days_approved' => $daysApproved,
            ]);

            $employeeUserAccount = $leaveRequest->employee->userAccount;

            $hodName = $hod->employee->name;
            // notify supervisor
            $hod->notify(new NotifyHodNotification([
                'leaveStatus' => $decision,
                'supervisor' => $hodName,
                'employee' => $employeeUserAccount->employee->name,
                'date' => $date
            ]));

            // notify employee
            $employeeUserAccount->notify(new LeaveStatusNotification([
                'leaveStatus' => $decision,
                'supervisor' => $hodName,
                'employee' => $employeeUserAccount->employee->name,
                'date' => $date
            ]));

            if ($decision === 'approved') {
                // notify HRs
                $users = User::role('hr')->get();

                foreach ($users as $item) {
                    if (!empty($item)) {
                        $item->notify(new LeaveStatusHrNotification([
                            'hr' => $item->employee?->name,
                            'supervisor' => $hodName,
                            'employee' => $employeeUserAccount->employee->name,
                            'date' => $date,
                            'leaveStatus' => $decision
                        ]));
                    }
                }
            }

            ActivityLog::add($hod->name . ' ' . $decision . ' ' . $daysApproved .
                ' day(s) leave request starting from ' . $request->start_date . ' to ' . $this->lastDate,
                $decision, [''], 'leave-request')
                ->to($leaveRequest)
                ->as($hod);

            DB::commit();

            return response()->json(new LeaveRequestResource($leaveRequest));
        } catch (Exception $exception) {
            DB::rollBack();
            Log::error('Change Leave Status: ', [$exception]);

            return response()->json('Something went wrong', 400);
        }
    }

    /**
     * @param HrChangeLeaveStatusRequest $request
     * @return JsonResponse
     */
    public function hrChangeLeaveStatus(HrChangeLeaveStatusRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $leaveRequest = LeaveRequest::where('uuid', $request->id)->first();

            $daysApproved = $this->validateLeaveDays($request->start_date, $request->days_requested);

            $user = Auth::user();
            $decision = $request->decision;

            $daysApproved =  $decision == 'approved' ? $daysApproved : 0;

            $leaveRequest->update([
                'days_approved' => $daysApproved,
                'start_date' => Carbon::parse($request->start_date)->format('Y-m-d'),
                'end_date' => $this->lastDate,
                'hr_reason' => $request->hr_reason,
                'hr_status' => $decision,
                'status' => $request->status,
                'hr_approval' => date('Y-m-d'),
                'hr_id' => $user?->employee->id
            ]);

            $leaveRequest->approvals()->create([
                'approved_by' => $user->id,
                'role' => 'hr',
                'decision' => $decision,
                'comment' => $request->sup_reason,
                'decided_at' => Carbon::now(),
                'days_approved' => $daysApproved,
            ]);

            ActivityLog::add($user->employee->name . ' ' . $decision . ' leave request', $decision, [''], 'leave-request')
                ->to($leaveRequest)
                ->as($user);

            /*$date = Carbon::now()->format('Y-m-d');

            $employeeUserAccount = $leaveRequest->employee->userAccount;

            $employeeUserAccount->notify(new LeaveStatusNotification([
                'leaveStatus' => $request->hr_status_update,
                'supervisor' => $employeeUserAccount->employee->name,
                'employee' => $user->employee->name,
                'date' => $date,
                'hasPendingText' => false
            ]));*/

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

    /**
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::where('uuid', $id)->firstOrFail();

        return response()->json(new LeaveRequestResource($leaveRequest));
    }

    /**
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function getMyLeaveRequest(Request $request): AnonymousResourceCollection
    {
        $auth = Auth::user();

        $leaveRequest = LeaveRequest::query();

        $leaveRequest->where('employee_id', $auth->employee->id);

        return LeaveRequestResource::collection($leaveRequest->paginate($request->per_page ?? 10));
    }

    public function getMyLeaveStats(Request $request): JsonResponse
    {
        $employeeId = Auth::user()->employee_id;

        $counts = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'total' => $counts->sum(),
            'pending' => $counts->get('pending', 0),
            'approved' => $counts->get('supervisor_approved', 0) + $counts->get('hr_approved', 0),
            'rejected' => $counts->get('supervisor_rejected', 0) + $counts->get('hr_rejected', 0)
        ]);
    }

    public function getMyLeaveBalance(Request $request): JsonResponse
    {
        $employee = Auth::user()->employee;

        $balances = LeaveTypeLevelConfig::with('leaveType') // eager load related leave type
        ->where('job_category_id', $employee->jobDetail->job_category_id)
            ->get()
            ->map(function ($config) use ($employee) {
                $usedDays = LeaveRequest::where('employee_id', $employee->id)
                    ->where('leave_type_id', $config->leave_type_id)
                    ->whereIn('status', ['hr_approved'])
                    ->sum('days_requested');

                return [
                    'leave_type_id' => $config->leave_type_id,
                    'type' => $config->leaveType->name,
                    'total' => $config->number_of_days,
                    'used' => $usedDays,
                    'remaining' => $config->number_of_days - $usedDays,
                ];
            });

        return response()->json($balances);
    }

    public function getUpcomingLeave(): AnonymousResourceCollection
    {
        $departmentId = Auth::user()->employee->department_id;
        $upcomingLeaves = LeaveRequest::with([
            'employee:id,uuid,first_name,middle_name,last_name,department_id,title,staff_id',
            'leaveType:id,name'
        ])->whereIn('status', [
            'hr_approved'
        ])
            ->whereDate('start_date', '>=', Carbon::today())
            ->whereHas('employee', function ($query) use ($departmentId) {
                $query->where('department_id', $departmentId);
            })
            ->orderBy('start_date')
            ->get();

        return UpcomingLeaveResource::collection($upcomingLeaves);
    }


    public function getTeamLeaveRequest()
    {
        if (!$this->isSupervisor()) {
            return response()->json([
                'message' => 'You do not have permission to view leave requests.'
            ], 403);
        }

        $departmentId = Auth::user()->employee->department_id;
        $upcomingLeaves = LeaveRequest::with([
            'employee:id,uuid,first_name,middle_name,last_name,department_id,title,staff_id',
            'leaveType:id,name'
        ])->whereHas('employee', function ($query) use ($departmentId) {
            $query->where('department_id', $departmentId);
        })->paginate(10);

        return LeaveRequestResource::collection($upcomingLeaves);
    }
}
