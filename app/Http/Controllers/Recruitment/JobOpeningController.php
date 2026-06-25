<?php

namespace App\Http\Controllers\Recruitment;

use App\Enums\JobOpeningStatus;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreJobOpeningRequest;
use App\Http\Requests\Recruitment\UpdateJobOpeningRequest;
use App\Http\Resources\Recruitment\JobOpeningResource;
use App\Models\Recruitment\JobOpening;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JobOpeningController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JobOpening::query()
            ->with(['position', 'department', 'applications'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->department_uuid, function ($q) use ($request) {
                $q->whereHas('department', fn($d) => $d->where('uuid', $request->department_uuid));
            })
            ->when($request->search, fn($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->latest();

        return JobOpeningResource::collection($query->paginate($request->per_page ?? 10));
    }

    public function store(StoreJobOpeningRequest $request): JobOpeningResource|JsonResponse
    {
        try {
            $jobOpening = JobOpening::create($request->validated());
            activity('recruitment')->performedOn($jobOpening)->log("Created job opening: {$jobOpening->title}");
            return new JobOpeningResource($jobOpening->load(['position', 'department']));
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function show(JobOpening $jobOpening): JsonResponse
    {
        $jobOpening->load(['position', 'department', 'applications.candidate']);
        return ApiResponse::success(new JobOpeningResource($jobOpening));
    }

    public function update(UpdateJobOpeningRequest $request, JobOpening $jobOpening): JobOpeningResource|JsonResponse
    {
        try {
            $jobOpening->update($request->validated());
            activity('recruitment')->performedOn($jobOpening)->log("Updated job opening: {$jobOpening->title}");
            return new JobOpeningResource($jobOpening->refresh()->load(['position', 'department']));
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy(JobOpening $jobOpening): JsonResponse
    {
        try {
            $title = $jobOpening->title;
            $jobOpening->delete();
            activity('recruitment')->log("Deleted job opening: {$title}");
            return response()->json(['message' => 'Job opening deleted']);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function publish(JobOpening $jobOpening): JsonResponse
    {
        $jobOpening->update(['status' => JobOpeningStatus::OPEN]);
        activity('recruitment')->performedOn($jobOpening)->log("Published job opening: {$jobOpening->title}");
        return ApiResponse::success(new JobOpeningResource($jobOpening), 'Job opening published');
    }

    public function close(JobOpening $jobOpening): JsonResponse
    {
        $jobOpening->update(['status' => JobOpeningStatus::CLOSED]);
        activity('recruitment')->performedOn($jobOpening)->log("Closed job opening: {$jobOpening->title}");
        return ApiResponse::success(new JobOpeningResource($jobOpening), 'Job opening closed');
    }
}
