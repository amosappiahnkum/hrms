<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmployeeResource;
use App\Models\Department;
use App\Models\EducationLevel;
use App\Models\Employee;
use App\Models\InformationUpdate;
use App\Models\LeaveRequest;
use App\Models\Position;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

class CommonController extends Controller
{

    public function getAllPermissions($id): JsonResponse
    {
        $employee = Employee::find($id)->userAccount;

        $permissions = Permission::all()->groupBy('group');

        if (!$employee) {
            return response()->json([
                'userPermissions' => [],
                'permissions' => $permissions
            ]);
        }
        $userPermissions = $employee->getAllPermissions()->pluck('id');

        return response()->json([
            'userPermissions' => $userPermissions,
            'permissions' => $permissions
        ]);
    }

    public function assignPermissions(Request $request): JsonResponse
    {
        $userAccount = Employee::find($request->employeeId)->userAccount;

        if (!$userAccount) {
            return response()->json('No User Account Found', 422);
        }

        DB::beginTransaction();
        try {
            $selectedPermissions = Permission::query()->whereIn('id', $request->permissions)->get()->pluck('name');

            $currentPermissions = $userAccount->getPermissionsViaRoles()->pluck('name');

            $userAccount->syncPermissions($selectedPermissions->diff($currentPermissions));
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Permission(s) Added',
                'data' => new EmployeeResource($userAccount->employee)
            ]);
        } catch (\Exception $exception) {
            DB::rollBack();

            Log::error('Assign Permissions: ', [$exception]);

            return response()->json([
                'status' => 'success',
                'message' => 'Something went wrong'
            ], 400);
        }
    }

    /**
     * @return array
     */
    public function getNotificationNavs(): array
    {
//        Log::info(Auth::user()?->can('approve-employee-update'));
        if ($this->isSupervisor() && $this->isHr()) {
            $approved = LeaveRequest::where('hr_status', 'approved')->orWhere('status', 'approved')->count();
            $pending = LeaveRequest::where('hr_status', 'pending')->orWhere('status', 'pending')->count();

            return $this->notificationData($approved, $pending);
        }

        if ($this->isSupervisor()) {
            $approved = LeaveRequest::where('status', 'approved')
                ->where('supervisor_id', Auth::user()->employee->id)->count();
            $pending = LeaveRequest::query()->where('status', 'pending')
                ->where('supervisor_id', Auth::user()->employee->id)->count();

            return $this->notificationData($approved, $pending);
        }

        if ($this->isHr()) {
            $approved = LeaveRequest::where('hr_status', 'approved')->count();
            $rejected = LeaveRequest::where('hr_status', 'rejected')->count();
            $pending = LeaveRequest::where('hr_status', 'pending')->where('status', 'approved')->count();


            return $this->notificationData($approved, $pending, $rejected);
        }

        return $this->notificationData();
    }

    public function notificationData($approved = 0, $pending = 0, $rejected = 0, $infoUpdates = 0): array
    {
        if (Auth::user()?->can('approve-employee-update')) {
            $pendingUpdate = InformationUpdate::query()->where('status', 'pending')->count();
            $approvedUpdate = InformationUpdate::query()->where('status', 'approved')->count();
            $rejectedUpdate = InformationUpdate::query()->where('status', 'rejected')->count();
            $informationUpdates = [
                'pending' => $pendingUpdate,
                'approved' => $approvedUpdate,
                'rejected' => $rejectedUpdate
            ];
        } else {
            $informationUpdates = [
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0
            ];
        }

        return [
            'leave_request' => [
                'approved' => $approved,
                'pending' => $pending,
                'rejected' => $rejected
            ],
            'info_updates' => $informationUpdates,
            'total' => $pending
        ];
    }

    public function getEmployeeManagementStats(): JsonResponse
    {
        $employees = Employee::query()->count();

        $males = Employee::query()->where("gender", "Male")->count();
        $females = Employee::query()->where("gender", "Female")->count();
        $employeeByDepartments = Department::query()->withCount('employees')->get()->pluck('employees_count',
            'name')->toArray();
        $departments = Department::query()->count();
        $positions = Position::query()->count();

        return response()->json([
            'employees' => $employees,
            'males' => $males,
            'females' => $females,
            'departments' => $departments,
            'positions' => $positions,
            'employeeByDepartments' => $employeeByDepartments,
        ]);
    }

    public function getEducationalLevels(): Collection
    {
        return EducationLevel::query()->select(['id', 'name'])->get();
    }
}
