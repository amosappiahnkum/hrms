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
use App\Notifications\Recruitment\RecruitmentNotification;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
        $application->load(['candidate', 'jobOpening']);
        $application->update(['status' => ApplicationStatus::SHORTLISTED]);

        $candidate  = $application->candidate;
        $jobTitle   = $application->jobOpening?->title ?? 'the position';

        $candidate->notify(new RecruitmentNotification([
            'subject'  => "Application Shortlisted — {$jobTitle}",
            'greeting' => "Dear {$candidate->first_name},",
            'lines'    => [
                "We are pleased to inform you that your application for the {$jobTitle} position has been shortlisted.",
                "Our team will be in touch shortly with further details about the next steps in our recruitment process.",
                "Thank you for your interest in joining our organisation.",
            ],
            'action_url'  => env('FRONTEND_URL') . '/candidate/my-applications',
            'action_text' => 'View My Applications',
        ]));

        return ApiResponse::success(new ApplicationResource($application), 'Application shortlisted');
    }

    public function reject(Application $application): JsonResponse
    {
        $application->load(['candidate', 'jobOpening']);
        $application->update(['status' => ApplicationStatus::REJECTED]);

        $candidate = $application->candidate;
        $jobTitle  = $application->jobOpening?->title ?? 'the position';

        $candidate->notify(new RecruitmentNotification([
            'subject'  => "Application Update — {$jobTitle}",
            'greeting' => "Dear {$candidate->first_name},",
            'lines'    => [
                "Thank you for your interest in the {$jobTitle} position and for the time you invested in your application.",
                "After careful consideration, we regret to inform you that your application has not progressed to the next stage of our recruitment process.",
                "We appreciate your interest in our organisation and encourage you to apply for other suitable openings in the future.",
            ],
        ]));

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
            $jobTitle    = $jobOpening?->title ?? 'the position';

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

            // Close all other open applications for this candidate
            Application::where('candidate_id', $candidate->id)
                ->where('id', '!=', $application->id)
                ->whereNotIn('status', [ApplicationStatus::HIRED, ApplicationStatus::REJECTED, ApplicationStatus::WITHDRAWN])
                ->update(['status' => ApplicationStatus::WITHDRAWN]);

            $candidate->notify(new RecruitmentNotification([
                'subject'  => "Congratulations — Offer for {$jobTitle}",
                'greeting' => "Dear {$candidate->first_name},",
                'lines'    => [
                    "We are delighted to inform you that your application for the {$jobTitle} position has been successful.",
                    "Our HR team will be in contact shortly with your offer letter and onboarding details.",
                    "We look forward to welcoming you to our team!",
                ],
                'action_url'  => env('FRONTEND_URL') . '/candidate/my-applications',
                'action_text' => 'View My Applications',
            ]));

            DB::commit();
            return ApiResponse::success(new EmployeeResource($employee), 'Candidate hired successfully');
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
