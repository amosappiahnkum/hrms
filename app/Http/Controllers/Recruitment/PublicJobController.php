<?php

namespace App\Http\Controllers\Recruitment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Recruitment\JobOpeningResource;
use App\Models\Recruitment\JobOpening;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicJobController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JobOpening::query()
            ->with(['position', 'department'])
            ->where('status', 'open')
            ->when($request->search, fn($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->when($request->department_uuid, function ($q) use ($request) {
                $q->whereHas('department', fn($d) => $d->where('uuid', $request->department_uuid));
            })
            ->latest();

        return JobOpeningResource::collection($query->paginate(10));
    }

    public function show(JobOpening $jobOpening): JsonResponse
    {
        $jobOpening->load(['position', 'department']);
        return ApiResponse::success(new JobOpeningResource($jobOpening));
    }
}
