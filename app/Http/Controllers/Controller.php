<?php

namespace App\Http\Controllers;

use App\Http\Resources\CelebrationResource;
use App\Models\Department;
use App\Models\EducationLevel;
use App\Models\Employee;
use App\Models\JobCategory;
use App\Models\Position;
use App\Models\Rank;
use App\Models\SubUnit;
use App\Models\TerminationReason;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function getCommonData()
    {
        $loggedInUser = Auth::user();

        if (!$loggedInUser) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 422);
        }

        $isStaff = $loggedInUser->getRoleNames()->contains('staff') || $loggedInUser->getRoleNames()->contains('admin');
        $educationalLevels = EducationLevel::all();
        $terminationReasons = TerminationReason::query()->select(['uuid', 'reason'])->get();
        $positions = Position::all();
        $jobCategories = JobCategory::all();
        $subUnits = SubUnit::all();
        $ranks = Rank::all();
        $departments = Department::all();
        $employees = Employee::count();
        $males = Employee::where('gender', 'Male')->count();
        $females = Employee::where('gender', 'Female')->count();
        $permissions = Permission::all()->groupBy('group');
        $celebrations = Employee::query();

        $celebrations->when($isStaff, static function ($q) {
            return $q->where('department_id', Auth::user()->employee->department_id);
        })->orderBy('dob')->limit(5);


        $celebrations = $celebrations->get();

        $dashboardRanks = Rank::query()->withCount('employees');

        $daa = ['Deputy Registrar', 'Senior Assistant Registrar', 'Associate Professor', 'Lecturer', 'Senior Lecturer'];

        $dashboardRanks->whereIn('name', $daa);

        $topDepartment = Department::query()->withCount('employees')
            ->orderByDesc('employees_count')->limit(24);

        return response([
            'educationalLevels' => $educationalLevels,
            'terminationReasons' => $terminationReasons,
            'jobCategories' => $jobCategories,
            'subUnits' => $subUnits,
            'departments' => $departments,
            'positions' => $positions,
            'ranks' => $ranks,
            'dashboard' => [
                'ranks' => $this->formatData($dashboardRanks),
                'departments' => $this->formatData($topDepartment),
            ],
            'employees' => [
                'total' => $employees,
                'male' => $males,
                'female' => $females
            ],
            'permissions' => $permissions,
            'celebrations' => CelebrationResource::collection($celebrations)
        ]);
    }

    public function formatData(Builder $builder): array
    {
        return [
            'series' => $builder->pluck('name'),
            'values' => $builder->pluck('employees_count')
        ];
    }


    public static function formatDate($date, string $format = 'Y-m-d'): ?string
    {
        if ($date == "" || $date == null) {
            return null;
        }

        return Carbon::parse($date)->format($format);
    }

    public function getRoles()
    {
        return Auth::user()?->getRoleNames();
    }

    public function can($permission)
    {
        return Auth::user()?->can($permission);
    }


    public function isHr(): bool
    {
        return $this->can('approve-leave') || $this->can('disapprove-leave');
    }

    public function isSupervisor(): bool
    {
        return $this->hasRole('hod') || $this->can('approve-leave-request') || $this->can('decline-leave-request');
    }

    public function isHrAdmin(): bool
    {
        return $this->hasRole('super-admin') || $this->hasRole('hr');
    }

    public function hasRole(string $role): bool
    {
        $user = Auth::user();

        return $user->hasRole($role);
    }
}
