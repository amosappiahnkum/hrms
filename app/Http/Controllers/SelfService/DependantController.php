<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDependantRequest;
use App\Http\Requests\UpdateDependantRequest;
use App\Http\Resources\DependantResource;
use App\Models\Dependant;
use App\Services\UpdateApprovalService;
use App\Traits\InformationUpdate;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Throwable;

class DependantController extends Controller
{
    use InformationUpdate;

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return AnonymousResourceCollection|Response|BinaryFileResponse
     */
    public function index(Request $request): Response|BinaryFileResponse|AnonymousResourceCollection
    {
        $dependants = Dependant::query();

        $dependants->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        });

        return DependantResource::collection($dependants->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreDependantRequest $request
     * @return JsonResponse
     */
    public function store(StoreDependantRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            if ($this->isHrAdmin()) {
                Dependant::create($validated);
            } else {

                app(UpdateApprovalService::class)->create(
                    new Dependant(),
                    $validated,
                    Auth::id()
                );
            }

            return ApiResponse::success(
                null,
                'Dependant creation request submitted for approval'
            );

        } catch (Throwable $e) {
            Log::error('Add Dependant Error', ['error' => $e]);

            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateDependantRequest $request
     * @param Dependant $dependant
     * @return DependantResource|JsonResponse
     * @throws Throwable
     */
    public function update(UpdateDependantRequest $request, Dependant $dependant): JsonResponse|DependantResource
    {
        DB::beginTransaction();
        try {

            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $dependant->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($dependant, $changes, Auth::id());
            }

            DB::commit();
            return new DependantResource($dependant);
        } catch (Exception $exception) {
            Log::error('Update Dependant Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }

    public function show(Dependant $dependant)
    {
        return ApiResponse::success(DependantResource::make($dependant));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Dependant $dependant
     * @return JsonResponse|null
     * @throws Throwable
     */
    public function destroy(Dependant $dependant): ?JsonResponse
    {
        DB::beginTransaction();
        try {

//            if ($this->isHrAdmin()) {
//                $dependant->delete();
//            } else {
                app(UpdateApprovalService::class)->delete($dependant, Auth::id());
//            }

            DB::commit();

            return ApiResponse::success(null, 'Delete request submitted for approval', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {

            Log::error('Delete Dependant Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
