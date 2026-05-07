<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StorePreviousPositionRequest;
use App\Http\Requests\UpdatePreviousPositionRequest;
use App\Http\Resources\PreviousPositionResource;
use App\Models\PreviousPosition;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class PreviousPositionController extends Controller
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
        $previousPositions = PreviousPosition::query();

        $previousPositions->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('start');

        return PreviousPositionResource::collection($previousPositions->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StorePreviousPositionRequest $request
     * @return PreviousPositionResource|JsonResponse
     * @throws \Throwable
     */
    public function store(StorePreviousPositionRequest $request): PreviousPositionResource|JsonResponse
    {
        try {
            $previousPosition = PreviousPosition::create($request->validated());

            return ApiResponse::success(PreviousPositionResource::make($previousPosition));
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param UpdatePreviousPositionRequest $request
     * @param PreviousPosition $previousPosition
     * @return PreviousPositionResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdatePreviousPositionRequest $request, PreviousPosition $previousPosition): JsonResponse|PreviousPositionResource
    {
        try {
            $previousPosition->update($request->validated());

            return ApiResponse::success(PreviousPositionResource::make($previousPosition));
        }catch (Exception $exception){
            Log::error($exception->getMessage());

            return ApiResponse::error('Something went wrong');
        }
    }

    public function show(PreviousPosition $previousPosition)
    {
        return ApiResponse::success(PreviousPositionResource::make($previousPosition));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param PreviousPosition $previousPosition
     * @return JsonResponse|null
     * @throws \Throwable
     */
    public function destroy(PreviousPosition $previousPosition): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $previousPosition->delete();
            DB::commit();
            return ApiResponse::success(null, 'Qualification deleted.', ResponseAlias::HTTP_OK);
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
