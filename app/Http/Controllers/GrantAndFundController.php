<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreGrantAndFundRequest;
use App\Http\Requests\UpdateGrantAndFundRequest;
use App\Http\Resources\GrantAndFundResource;
use App\Models\GrantAndFund;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class GrantAndFundController extends Controller
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
        $grantAndFunds = GrantAndFund::query();

        $grantAndFunds->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('start');

        return GrantAndFundResource::collection($grantAndFunds->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreGrantAndFundRequest $request
     * @return GrantAndFundResource|JsonResponse
     * @throws \Throwable
     */
    public function store(StoreGrantAndFundRequest $request): GrantAndFundResource|JsonResponse
    {
        try {
            $grant = GrantAndFund::create($request->validated());

            return ApiResponse::success(GrantAndFundResource::make($grant));
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param UpdateGrantAndFundRequest $request
     * @param GrantAndFund $grant
     * @return GrantAndFundResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateGrantAndFundRequest $request, GrantAndFund $grant): JsonResponse|GrantAndFundResource
    {
        try {
            $grant->update($request->validated());

            return ApiResponse::success(GrantAndFundResource::make($grant));
        }catch (Exception $exception){
            Log::error($exception->getMessage());

            return ApiResponse::error('Something went wrong');
        }
    }

    public function show(GrantAndFund $grant)
    {
        return ApiResponse::success(GrantAndFundResource::make($grant));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param GrantAndFund $grant
     * @return JsonResponse|null
     * @throws \Throwable
     */
    public function destroy(GrantAndFund $grant): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $grant->delete();
            DB::commit();
            return ApiResponse::success(null, 'GrantAndFund deleted.', ResponseAlias::HTTP_OK);
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
