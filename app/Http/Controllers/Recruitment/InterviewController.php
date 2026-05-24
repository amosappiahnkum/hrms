<?php

namespace App\Http\Controllers\Recruitment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreInterviewRequest;
use App\Http\Requests\Recruitment\UpdateInterviewRequest;
use App\Http\Resources\Recruitment\InterviewResource;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Interview;
use App\Models\SelfService\Employee;
use App\Notifications\Recruitment\RecruitmentNotification;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;

class InterviewController extends Controller
{
    public function all(): AnonymousResourceCollection
    {
        $interviews = Interview::with(['interviewers', 'application.candidate'])->latest()->get();
        return InterviewResource::collection($interviews);
    }

    public function index(Application $application): AnonymousResourceCollection
    {
        $interviews = $application->interviews()->with('interviewers')->latest()->get();
        return InterviewResource::collection($interviews);
    }

    public function store(StoreInterviewRequest $request, Application $application): InterviewResource|JsonResponse
    {
        try {
            $data = collect($request->validated())->except('interviewer_uuids')->toArray();
            $interview = $application->interviews()->create($data);

            $interviewerIds = Employee::whereIn('uuid', $request->interviewer_uuids ?? [])->pluck('id');
            $interview->interviewers()->sync($interviewerIds);

            // Reload with full relations for notification
            $interview->load(['interviewers', 'application.candidate', 'application.jobOpening']);
            $candidate  = $interview->application?->candidate;
            $jobTitle   = $interview->application?->jobOpening?->title ?? 'the position';
            $scheduledAt = Carbon::parse($interview->scheduled_at)->format('D, d M Y \a\t H:i');
            $typeLabel  = $interview->type ? ucwords(str_replace('_', ' ', $interview->type->value)) : null;

            // Notify candidate
            if ($candidate) {
                $lines = [
                    "An interview has been scheduled for your application to the {$jobTitle} position.",
                    "Date & Time: {$scheduledAt}",
                    $typeLabel ? "Format: {$typeLabel}" : null,
                    $interview->location ? "Location / Link: {$interview->location}" : null,
                    $interview->notes ? "Notes: {$interview->notes}" : null,
                    "Please ensure you are available at the scheduled time.",
                ];
                $candidate->notify(new RecruitmentNotification([
                    'subject'     => "Interview Scheduled — {$jobTitle}",
                    'greeting'    => "Dear {$candidate->first_name},",
                    'lines'       => array_filter($lines),
                    'action_url'  => env('FRONTEND_URL') . '/candidate/my-applications',
                    'action_text' => 'View My Applications',
                ]));
            }

            // Notify each interviewer
            foreach ($interview->interviewers as $interviewer) {
                $email = $interviewer->contactDetail?->work_email ?? $interviewer->work_email;
                if (!$email) continue;
                Notification::route('mail', $email)->notify(new RecruitmentNotification([
                    'subject'  => "Interview Assignment — {$candidate?->name} for {$jobTitle}",
                    'greeting' => "Dear {$interviewer->name},",
                    'lines'    => array_filter([
                        "You have been assigned to interview {$candidate?->name} for the {$jobTitle} position.",
                        "Date & Time: {$scheduledAt}",
                        $typeLabel ? "Format: {$typeLabel}" : null,
                        $interview->location ? "Location / Link: {$interview->location}" : null,
                        $interview->notes ? "Notes: {$interview->notes}" : null,
                    ]),
                ]));
            }

            return new InterviewResource($interview->load('interviewers'));
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function show(Interview $interview): JsonResponse
    {
        $interview->load(['interviewers', 'application.candidate']);
        return ApiResponse::success(new InterviewResource($interview));
    }

    public function update(UpdateInterviewRequest $request, Interview $interview): InterviewResource|JsonResponse
    {
        try {
            $data = collect($request->validated())->except('interviewer_uuids')->toArray();
            $interview->update($data);

            if ($request->has('interviewer_uuids')) {
                $interviewerIds = Employee::whereIn('uuid', $request->interviewer_uuids ?? [])->pluck('id');
                $interview->interviewers()->sync($interviewerIds);
            }

            return new InterviewResource($interview->refresh()->load('interviewers'));
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy(Interview $interview): JsonResponse
    {
        try {
            $interview->delete();
            return response()->json(['message' => 'Interview deleted']);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
