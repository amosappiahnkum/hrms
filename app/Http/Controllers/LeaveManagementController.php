<?php

namespace App\Http\Controllers;

use App\Http\Resources\LeaveRequestResource;
use App\Models\Config\LeaveType;
use App\Models\Config\LeaveTypeLevelConfig;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveRequest;
use App\Models\SelfService\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class LeaveManagementController extends Controller
{
    /**
     * @param Request $request
     *
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function getLeaveRequests(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if ($this->isHr() || $this->isSupervisor()) {
            if ($this->isHr() && $this->isSupervisor()) {
                $leaveRequestQuery = LeaveRequest::query();
                $leaveRequestQuery->when($request->has('hr_status'), function ($q) use ($request) {
                    return $q->where('hr_status', strtolower($request->hr_status))
                        ->orWhere('status', strtolower($request->hr_status));
                });

                $leaveRequestQuery->where('supervisor_id', Auth::user()->employee->id);

                return LeaveRequestResource::collection($leaveRequestQuery->paginate(10));
            }

            if ($this->isHr()) {
                $leaveRequestQuery = LeaveRequest::query();
                $leaveRequestQuery->when($request->has('hr_status'), function ($q) use ($request) {
                    return $q->where('hr_status', strtolower($request->hr_status));
                });

                $leaveRequestQuery->where('status', 'approved');
                return LeaveRequestResource::collection($leaveRequestQuery->paginate(10));
            }

            if ($this->isSupervisor()) {
                $leaveRequestQuery = LeaveRequest::query();
                $leaveRequestQuery->when($request->has('hr_status'), function ($q) use ($request) {
                    return $q->where('status', strtolower($request->hr_status));
                });

                $leaveRequestQuery->where('supervisor_id', Auth::user()->employee->id);

                return LeaveRequestResource::collection($leaveRequestQuery->paginate(10));
            }
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Not enough permissions'
        ], 400);
    }

    public function getEmployeeLeaveBalances(Request $request): JsonResponse
    {
        if (!$this->isHrAdmin()) {
            return response()->json(['message' => 'Insufficient permissions.'], 403);
        }

        $leaveTypes = LeaveType::all(['id', 'name']);

        $employees = Employee::query()
            ->with(['jobDetail', 'department'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('department_id'), fn($q) => $q->where('department_id', $request->department_id))
            ->whereHas('jobDetail')
            ->paginate($request->per_page ?? 20);

        $data = $employees->through(function (Employee $employee) use ($leaveTypes) {
            $jobCategoryId = $employee->jobDetail?->job_category_id;

            $configs = LeaveTypeLevelConfig::where('job_category_id', $jobCategoryId)->get()->keyBy('leave_type_id');

            $balances = $leaveTypes->map(function (LeaveType $type) use ($employee, $configs) {
                $config = $configs->get($type->id);
                if (!$config) {
                    return null;
                }

                $adjustment = LeaveBalanceAdjustment::where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->sum('days');

                $total = $config->number_of_days + $adjustment;

                $used = LeaveRequest::where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->where('status', 'hr_approved')
                    ->sum('days_approved');

                $pending = LeaveRequest::where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->whereIn('status', ['pending', 'hod_approved'])
                    ->sum('days_requested');

                return [
                    'leave_type_id' => $type->id,
                    'type' => $type->name,
                    'total' => $total,
                    'used' => $used,
                    'pending' => $pending,
                    'remaining' => max(0, $total - $used),
                    'available' => max(0, $total - $used - $pending),
                    'adjustment' => $adjustment,
                ];
            })->filter()->values();

            return [
                'id' => $employee->id,
                'uuid' => $employee->uuid,
                'name' => $employee->name,
                'staff_id' => $employee->staff_id,
                'department' => $employee->department?->name,
                'balances' => $balances,
            ];
        });

        return response()->json($data);
    }

    public function adjustEmployeeBalance(Request $request): JsonResponse
    {
        if (!$this->isHrAdmin()) {
            return response()->json(['message' => 'Insufficient permissions.'], 403);
        }

        $request->validate([
            'employee_id' => 'required|exists:employees,uuid',
            'leave_type_id' => 'required|exists:leave_types,id',
            'days' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:500',
        ]);

        $employee = Employee::where('uuid', $request->employee_id)->firstOrFail();

        LeaveBalanceAdjustment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $request->leave_type_id,
            'days' => $request->days,
            'reason' => $request->reason,
            'adjusted_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Balance adjusted successfully.']);
    }

    /**
     * @return JsonResponse
     */
    public function getFilterParams(): JsonResponse
    {
        $supervisors = Employee::query()->has('supervisorLeaveApprovals')->get();

        $hrs = Employee::query()->has('hrLeaveApprovals')->get();

        return response()->json([
            "status" => "success",
            "message" => "Filter Params",
            "data" => [
                'supervisors' => $supervisors,
                'hrs' => $hrs,
            ]
        ]);
    }
}
