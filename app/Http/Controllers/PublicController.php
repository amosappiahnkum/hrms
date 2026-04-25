<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\AchievementResource;
use App\Http\Resources\AffiliationResource;
use App\Http\Resources\AwardResource;
use App\Http\Resources\ExperienceResource;
use App\Http\Resources\GrantAndFundResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\QualificationResource;
use App\Http\Resources\StaffDirectory\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function getEmployees(Request $request)
    {
        $employees = Employee::query()
            ->when($request->department, fn ($q, $v) => $q->where('department', $v))
            ->when($request->faculty,    fn ($q, $v) => $q->where('faculty', $v))
            ->when($request->search,     fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('first_name', 'like', "%{$v}%")
                    ->orWhere('last_name',  'like', "%{$v}%")
                    ->orWhere('email',      'like', "%{$v}%");
            }))
            ->orderBy($request->input('sort_by', 'last_name'), $request->input('sort_dir', 'asc'))
            ->paginate(12)
            ->withQueryString(); // preserves ?search=, ?department= etc. in pagination links


        return EmployeeResource::collection($employees);
    }

    public function getEmployee(Request $request)
    {
        $employee = Employee::query()->where('uuid', $request->employee)->first();

        return ApiResponse::success(EmployeeResource::make($employee));
    }

    public function getEmployeeStats(Request $request) {
        $stats = $this->empStats($request->employee);

        return ApiResponse::success($stats);
    }

    public function getPublications(Employee $employee)
    {
        $publications = $employee->publications;

        return ApiResponse::success($publications);
    }

    public function getQualifications(Request $request, Employee $employee)
    {
        $qualifications = $employee->qualifications()->paginate($request->per_page);

        return QualificationResource::collection($qualifications);
    }

    public function getExperiences(Request $request, Employee $employee)
    {
        $qualifications = $employee->experiences()->paginate($request->per_page);

        return ExperienceResource::collection($qualifications);
    }

    public function getPreviousPositions(Request $request, Employee $employee)
    {
        $qualifications = $employee->previousPositions()->paginate($request->per_page);

        return ExperienceResource::collection($qualifications);
    }

    public function getSpecializations(Employee $employee)
    {
        return ApiResponse::success($employee->specializations, 'Specializations');
    }

    public function getResearchInterests(Employee $employee)
    {
        return ApiResponse::success($employee->research_interests, 'Research Interests');
    }

    public function getAwards(Request $request, Employee $employee)
    {
        $awards = $employee->awards()->paginate($request->per_page);

        return AwardResource::collection($awards);
    }

    public function getAchievements(Request $request, Employee $employee)
    {
        $achievements = $employee->achievements()->paginate($request->per_page);

        return AchievementResource::collection($achievements);
    }

    public function getAffiliations(Request $request, Employee $employee)
    {
        $affiliations = $employee->affiliations()->paginate($request->per_page);

        return AffiliationResource::collection($affiliations);
    }

    public function getGrants(Request $request, Employee $employee)
    {
        $grants = $employee->grantAndFunds()->paginate($request->per_page);

        return GrantAndFundResource::collection($grants);
    }

    public function getProjects(Request $request, Employee $employee)
    {
        $projects = $employee->projects()->paginate($request->per_page);

        return ProjectResource::collection($projects);
    }
}
