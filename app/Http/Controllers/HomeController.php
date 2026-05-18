<?php

namespace App\Http\Controllers;

use App\Http\Resources\LeaveRequestResource;
use App\Http\Resources\MyTeamResource;
use App\Models\EmployeeSupervisor;
use App\Models\LeaveRequest;
use App\Models\SelfService\Employee;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
//    public function __construct()
//    {
//        $this->middleware('auth');
//    }

    public function index(): Factory|View|Application
    {
        return view('home');
    }


    /**
     * @param Employee $employee
     *
     * @return JsonResponse
     */
    public function getPendingApprovals(Employee $employee): JsonResponse
    {
        $leaveRequest = LeaveRequest::query();

        $leaveRequest->where('supervisor_id', $employee->id)->where('status', 'pending');

        $isHr = $this->can('move-leave') || $this->can('approve-leave') ||
            $this->can('disapprove-leave');

        $leaveRequest->when($isHr, function ($q) {
            $q->orWhere('hr_status', 'pending')->where('status', 'approved');
        });

        return response()->json([
            'leaveRequest' => LeaveRequestResource::collection($leaveRequest->limit(2)->get())
        ]);
    }

    public function getMyTeam(): JsonResponse
    {
        $myTeam = EmployeeSupervisor::where('supervisor_id', Auth::user()->employee->id)->get();

        return response()->json(MyTeamResource::collection($myTeam));
    }

    public function getWhoIsOut(): JsonResponse
    {
        $now = Carbon::now();
        $weekStartDate = $now->startOfWeek()->format('Y-m-d');
        $weekEndDate = $now->endOfWeek()->format('Y-m-d');

        $leaveRequest = LeaveRequest::query()
            ->whereDate('start_date', '<=', $weekStartDate)
            ->orWhereDate('end_date', '>=', $weekEndDate)
            ->where('status', 'approved')->get();

        return response()->json(LeaveRequestResource::collection($leaveRequest));
    }
}
