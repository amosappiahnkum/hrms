<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreAffiliationRequest;
use App\Http\Requests\UpdateAffiliationRequest;
use App\Http\Resources\AffiliationResource;
use App\Models\Affiliation;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class AffiliationController extends Controller
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
        $affiliations = Affiliation::query();

        $affiliations->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('start');

        return AffiliationResource::collection($affiliations->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreAffiliationRequest $request
     * @return AffiliationResource|JsonResponse
     * @throws \Throwable
     */
    public function store(StoreAffiliationRequest $request): AffiliationResource|JsonResponse
    {
        try {
            $affiliation = Affiliation::create($request->validated());

            return ApiResponse::success(AffiliationResource::make($affiliation));
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param UpdateAffiliationRequest $request
     * @param Affiliation $affiliation
     * @return AffiliationResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateAffiliationRequest $request, Affiliation $affiliation): JsonResponse|AffiliationResource
    {
        try {
            $affiliation->update($request->validated());

            return ApiResponse::success(AffiliationResource::make($affiliation));
        }catch (Exception $exception){
            Log::error($exception->getMessage());

            return ApiResponse::error('Something went wrong');
        }
    }

    public function show(Affiliation $affiliation)
    {
        return ApiResponse::success(AffiliationResource::make($affiliation));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Affiliation $affiliation
     * @return JsonResponse|null
     * @throws \Throwable
     */
    public function destroy(Affiliation $affiliation): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $affiliation->delete();
            DB::commit();
            return ApiResponse::success(null, 'Affiliation deleted.', ResponseAlias::HTTP_OK);
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
