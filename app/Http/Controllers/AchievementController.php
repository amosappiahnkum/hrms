<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreAchievementRequest;
use App\Http\Requests\UpdateAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class AchievementController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $achievements = Achievement::query();

        $achievements->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('year');

        return AchievementResource::collection($achievements->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreAchievementRequest $request
     * @return AchievementResource|JsonResponse
     * @throws \Throwable
     */
    public function store(StoreAchievementRequest $request): AchievementResource|JsonResponse
    {
        try {
            $achievement = Achievement::create($request->validated());

            return ApiResponse::success(AchievementResource::make($achievement));
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param UpdateAchievementRequest $request
     * @param Achievement $achievement
     * @return AchievementResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateAchievementRequest $request, Achievement $achievement): JsonResponse|AchievementResource
    {
        try {
            $achievement->update($request->validated());

            return ApiResponse::success(AchievementResource::make($achievement));
        }catch (Exception $exception){
            Log::error($exception->getMessage());

            return ApiResponse::error('Something went wrong');
        }
    }

    public function show(Achievement $achievement)
    {
        return ApiResponse::success(AchievementResource::make($achievement));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Achievement $achievement
     * @return JsonResponse|null
     * @throws \Throwable
     */
    public function destroy(Achievement $achievement): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $achievement->delete();
            DB::commit();
            return ApiResponse::success(null, 'Achievement deleted.', ResponseAlias::HTTP_OK);
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
