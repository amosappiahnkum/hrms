<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreAwardRequest;
use App\Http\Requests\UpdateAwardRequest;
use App\Http\Resources\AwardResource;
use App\Models\Award;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class AwardController extends Controller
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
        $awards = Award::query();

        $awards->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('year');

        return AwardResource::collection($awards->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreAwardRequest $request
     * @return AwardResource|JsonResponse
     * @throws \Throwable
     */
    public function store(StoreAwardRequest $request): AwardResource|JsonResponse
    {
        try {
            $award = Award::create($request->validated());

            return ApiResponse::success(AwardResource::make($award));
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param UpdateAwardRequest $request
     * @param Award $award
     * @return AwardResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateAwardRequest $request, Award $award): JsonResponse|AwardResource
    {
        try {
            $award->update($request->validated());

            return ApiResponse::success(AwardResource::make($award));
        }catch (Exception $exception){
            Log::error($exception->getMessage());

            return ApiResponse::error('Something went wrong');
        }
    }

    public function show(Award $award)
    {
        return ApiResponse::success(AwardResource::make($award));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Award $award
     * @return JsonResponse|null
     * @throws \Throwable
     */
    public function destroy(Award $award): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $award->delete();
            DB::commit();
            return ApiResponse::success(null, 'Award deleted.', ResponseAlias::HTTP_OK);
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
