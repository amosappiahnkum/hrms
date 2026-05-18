<?php

namespace App\Http\Controllers;

use App\Http\Resources\LeaveRequestResource;
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
