<?php

namespace App\Http\Controllers\Recruitment;

use App\Enums\ApplicationStatus;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\HireCandidateRequest;
use App\Http\Requests\Recruitment\StoreApplicationRequest;
use App\Http\Requests\Recruitment\UpdateApplicationRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\Recruitment\ApplicationResource;
use App\Models\Recruitment\Application;
use App\Models\SelfService\Employee;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Application::query()
            ->with(['candidate', 'jobOpening'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->job_opening_uuid, function ($q) use ($request) {
                $q->whereHas('jobOpening', fn($j) => $j->where('uuid', $request->job_opening_uuid));
            })
            ->latest();

        return ApplicationResource::collection($query->paginate($request->per_page ?? 10));
    }

    public function store(StoreApplicationRequest $request): ApplicationResource|JsonResponse
    {
        try {
            $application = Application::create($request->validated());
            return new ApplicationResource($application->load(['candidate', 'jobOpening']));
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function show(Application $application): JsonResponse
    {
        $application->load(['candidate', 'jobOpening', 'interviews.interviewer', 'offer']);
        return ApiResponse::success(new ApplicationResource($application));
    }

    public function update(UpdateApplicationRequest $request, Application $application): ApplicationResource|JsonResponse
    {
        try {
            $application->update($request->validated());
            return new ApplicationResource($application->refresh()->load(['candidate', 'jobOpening']));
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy(Application $application): JsonResponse
    {
        try {
            $application->delete();
            return response()->json(['message' => 'Application deleted']);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function shortlist(Application $application): JsonResponse
    {
        $application->update(['status' => ApplicationStatus::SHORTLISTED]);
        return ApiResponse::success(new ApplicationResource($application), 'Application shortlisted');
    }

    public function reject(Application $application): JsonResponse
    {
        $application->update(['status' => ApplicationStatus::REJECTED]);
        return ApiResponse::success(new ApplicationResource($application), 'Application rejected');
    }

    public function hire(HireCandidateRequest $request, Application $application): JsonResponse
    {
        if ($application->status === ApplicationStatus::HIRED) {
            return ApiResponse::error('Candidate has already been hired');
        }

        DB::beginTransaction();
        try {
            $candidate   = $application->candidate;
            $jobOpening  = $application->jobOpening;

            $employee = Employee::create([
                'first_name'    => $candidate->first_name,
                'last_name'     => $candidate->last_name,
                'middle_name'   => $request->middle_name ?? '',
                'title'         => $request->title ?? '',
                'gender'        => $request->gender ?? '',
                'job_type'      => $request->job_type ?? '',
                'staff_id'      => $request->staff_id,
                'department_id' => $jobOpening?->department_id,
                'onboarding'    => true,
            ]);

            $employee->contactDetail()->create();
            $employee->jobDetail()->create([
                'position_id' => $jobOpening?->position_id,
                'joined_date' => $request->start_date ?? now()->format('Y-m-d'),
            ]);

            $application->update([
                'status'      => ApplicationStatus::HIRED,
                'employee_id' => $employee->id,
            ]);

            DB::commit();
            return ApiResponse::success(new EmployeeResource($employee), 'Candidate hired successfully');
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
