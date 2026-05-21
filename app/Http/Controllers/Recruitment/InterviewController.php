<?php

namespace App\Http\Controllers\Recruitment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreInterviewRequest;
use App\Http\Requests\Recruitment\UpdateInterviewRequest;
use App\Http\Resources\Recruitment\InterviewResource;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Interview;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InterviewController extends Controller
{
    public function index(Application $application): AnonymousResourceCollection
    {
        $interviews = $application->interviews()->with('interviewer')->latest()->get();
        return InterviewResource::collection($interviews);
    }

    public function store(StoreInterviewRequest $request, Application $application): InterviewResource|JsonResponse
    {
        try {
            $interview = $application->interviews()->create($request->validated());
            return new InterviewResource($interview->load('interviewer'));
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function show(Interview $interview): JsonResponse
    {
        $interview->load(['interviewer', 'application.candidate']);
        return ApiResponse::success(new InterviewResource($interview));
    }

    public function update(UpdateInterviewRequest $request, Interview $interview): InterviewResource|JsonResponse
    {
        try {
            $interview->update($request->validated());
            return new InterviewResource($interview->refresh()->load('interviewer'));
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
