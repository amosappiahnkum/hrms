<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreDependantRequest;
use App\Http\Requests\UpdateDependantRequest;
use App\Http\Resources\DependantResource;
use App\Models\Dependant;
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
     * @return DependantResource|JsonResponse
     * @throws \Throwable
     */
    public function store(StoreDependantRequest $request): JsonResponse|DependantResource
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();

            if ($this->isHrAdmin()) {
                $request['user_id'] = $user->id;
                $dependant = Dependant::create($request->validated());
            } else {
                $dependant = Dependant::create(['user_id' => $user->id]);

                $this->infoDifference($dependant, $request->validated());
                $this->requestUpdate($dependant);
            }

            /* ActivityLog::add(($user?->employee?->name ?? $user->username) . ' added emergency contact for ' . $employee->name,
                 'created', [''], 'dependant')
                 ->to($employee)
                 ->as($user);*/


            DB::commit();
            return new DependantResource($dependant);
        } catch (Exception $exception) {
            Log::error('Add Dependant Error: ', [$exception]);

            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateDependantRequest $request
     * @param Dependant $dependant
     * @return DependantResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateDependantRequest $request, Dependant $dependant): JsonResponse|DependantResource
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $dependant->update($request->all());
                $dependant->save();
            } else {
                $this->infoDifference($dependant, $request->all());
                $this->requestUpdate($dependant);
            }


            DB::commit();
            return new DependantResource($dependant);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
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
     * @throws \Throwable
     */
    public function destroy(Dependant $dependant): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $dependant->informationUpdate()->delete();
            $dependant->delete();

            DB::commit();

            return ApiResponse::success(null, 'Dependant deleted.', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {

            Log::error('Delete Dependant Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
