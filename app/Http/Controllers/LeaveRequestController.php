<?php

namespace App\Http\Controllers;

use App\Helpers\LeaveHelper;
use App\Http\Requests\HrChangeLeaveStatusRequest;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Resources\UpcomingLeaveResource;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveTypeLevelConfig;
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

class LeaveRequestController extends Controller
{

    private LeaveHelper $leaveHelper;

    public function __construct()
    {
        $this->middleware('auth');
        $this->leaveHelper = new LeaveHelper();
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
            $employee = Auth::user()->employee;

            $hod = $employee->department->headOfDepartment;

            if (empty($hod)) {
                return response()->json([
                    'message' => 'No HOD assigned to your department.'
                ], 400);
            }


            $startDate = Carbon::parse($request->start_date)->format('Y-m-d');
            $daysRequested = $request->number_of_days;

            $this->leaveHelper->validateLeaveDays($startDate, $daysRequested);

            $leaveType = LeaveType::where('uuid', $request->leave_type_id)->first();

            $leaveRequest = LeaveRequest::create([
                'employee_id' => $employee->id,
                'supervisor_id' => $hod->id,
                'department_id' => $employee->department->id,
                'leave_type_id' => $leaveType->id,
                'days_requested' => $daysRequested,
                'start_date' => $startDate,
                'reason' => $request->reason,
                'end_date' => $this->leaveHelper->lastDate,
            ]);

            $startDate = Carbon::parse($startDate)->format('l, M d Y');
            $endDate = Carbon::parse($this->leaveHelper->lastDate)->format('l, M d Y');

            $mailData = [
                'subject' => 'Time off Request',
                'greeting' => "Dear $hod->first_name!",
            ];

            if ($hod->id == $employee->id) { // hod is making request
                $leaveRequest->approvals()->create([
                    'approved_by' => $hod->id,
                    'role' => 'hod',
                    'decision' => 'approved',
                    'comment' => "Automatically approved leave request.",
                    'decided_at' => Carbon::now(),
                    'days_approved' => $daysRequested,
                ]);
                $leaveRequest->update(['status' => 'hod_approved']);

                $mailData['lines'] = [
                    "Your $daysRequested day(s) leave request has been sent, pending HR decision."
                ];

                $hrMailData['lines'] = [
                    "Please note that $employee->name has submitted a $daysRequested day leave request, which is currently pending your action."
                ];
            } else {
                $mailData['lines'] = [
                    "$employee->name has requested $daysRequested day(s) off,",
                    "Starting from $startDate and will resume on $endDate",
                ];

                $hrMailData['lines'] = [
                    "Please note that $employee->name has submitted a $daysRequested day leave request, which is currently pending action from the Head of Department"
                ];
            }

            // notify employee about request
            Notification::route('mail', $hod->contactDetail->work_email)
                ->notify(new LeaveRequestNotification($mailData));

            $this->leaveHelper->notifyAllHrs($hrMailData);

            DB::commit();

            return response()->json(new LeaveRequestResource($leaveRequest));
        } catch (Exception $exception) {
            DB::rollBack();

            Log::error('Leave Request Error', [$exception]);

            return response()->json('Something went wrong', 400);
        }
    }


    /**
     * @return Collection
     */
    public function getHolidays(): Collection
    {
        return $this->leaveHelper->getHolidays();
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
                    $this->leaveHelper->validateLeaveDays($request->start_date, $request->days_requested)
                    : $request->days_requested;

            $decision = $request->decision;

            $daysApproved = $decision == 'approved' ? $daysApproved : 0;
            $leaveRequest->update([
                'days_approved' => $daysApproved,
                'start_date' => Carbon::parse($request->start_date)->format('Y-m-d'),
                'status' => $request->status,
                'sup_reason' => $request->sup_reason,
                'end_date' => $this->leaveHelper->lastDate ?: $leaveRequest->end_date,
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

            $emp = $employeeUserAccount->employee->name;

            $mailData = [
                'subject' => "Leave request $decision",
                'greeting' => "Dear $emp!",
            ];

            if ($decision == 'approved') {
                $mailData['lines'] = [
                    "We are pleased to inform you that your leave request has been $decision by $hodName,pending HR decision.",
                    "If you have any questions or require further clarification, please do not hesitate to reach out."
                ];

                // notify HRs
                $hrMailData['lines'] = [
                    "Please note that $hodName $decision a $daysApproved day leave request for $emp, which is currently pending your action"
                ];

                $this->leaveHelper->notifyAllHrs($hrMailData);

            } else {
                $mailData['lines'] = [
                    "We regret to inform you that your leave request has been reviewed and has not been approved.",
                    "If you have any questions or would like to discuss the decision further, please feel free to reach out."
                ];
            }

            // notify employee
            $employeeUserAccount->notify(new LeaveStatusNotification($mailData));

            ActivityLog::add($hod->name . ' ' . $decision . ' ' . $daysApproved .
                ' day(s) leave request starting from ' . $request->start_date . ' to ' . $this->leaveHelper->lastDate,
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
            $user = Auth::user();

            $leaveRequest = LeaveRequest::where('uuid', $request->id)->first();

            $daysApproved = $this->leaveHelper->validateLeaveDays($request->start_date, $request->days_requested);

            $decision = $request->decision;

            $daysApproved = $decision == 'approved' ? $daysApproved : 0;

            $start = Carbon::parse($request->start_date);
            $end = Carbon::parse($this->leaveHelper->lastDate);
            $leaveRequest->update([
                'days_approved' => $daysApproved,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
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

            $employeeUserAccount = $leaveRequest->employee->userAccount;

            $employee = $employeeUserAccount->employee->first_name;
            $hr = $user->employee->first_name;

            $mailData = [
                'subject' => "Leave Request $decision",
                'greeting' => "Dear $employee!",
            ];

            if ($decision == 'approved') {
                $start = $start->format('F d, Y');
                $end = $end->format('F d, Y');
                $mailData['lines'] = [
                    "We are pleased to inform you that your leave request has been $decision by $hr.",
                    "Your approved leave period will commence on $start, and conclude on $end.",
                    "If you have any questions or require further clarification, please do not hesitate to reach out."
                ];
            } else {
                $mailData['lines'] = [
                    "We regret to inform you that your leave request has been reviewed and has not been approved.",
                    "If you have any questions or would like to discuss the decision further, please feel free to reach out."
                ];
            }

            $employeeUserAccount->notify(new LeaveStatusNotification($mailData));

            DB::commit();

            return response()->json(new LeaveRequestResource($leaveRequest));
        } catch (Exception $exception) {
            DB::rollBack();
            Log::error('HR Change Leave Status: ', [$exception]);

            return response()->json('Something went wrong', 400);
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


    public function getTeamLeaveRequest(): JsonResponse|AnonymousResourceCollection
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
