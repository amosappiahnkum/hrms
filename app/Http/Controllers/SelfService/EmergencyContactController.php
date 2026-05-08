<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmergencyContactRequest;
use App\Http\Requests\UpdateEmergencyContactRequest;
use App\Http\Resources\EmergencyContactResource;
use App\Models\EmergencyContact;
use App\Services\UpdateApprovalService;
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

class EmergencyContactController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return AnonymousResourceCollection|Response|BinaryFileResponse
     */
    public function index(Request $request): Response|BinaryFileResponse|AnonymousResourceCollection
    {
        $emergencyContacts = EmergencyContact::query();

        $emergencyContacts->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        });

        return EmergencyContactResource::collection($emergencyContacts->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreEmergencyContactRequest $request
     * @return EmergencyContactResource|JsonResponse
     * @throws Throwable
     */
    public function store(StoreEmergencyContactRequest $request): JsonResponse|EmergencyContactResource
    {
        try {
            $validated = $request->validated();

            if ($this->isHrAdmin()) {
                EmergencyContact::create($validated);
            } else {
                app(UpdateApprovalService::class)->create(
                    new EmergencyContact(),
                    $validated,
                    Auth::id()
                );
            }

            return ApiResponse::success(
                null,
                'Emergency Contact creation request submitted for approval'
            );

        } catch (Throwable $e) {
            Log::error('Add EmergencyContact Error', ['error' => $e]);

            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateEmergencyContactRequest $request
     * @param EmergencyContact $emergencyContact
     * @return EmergencyContactResource|JsonResponse
     * @throws Throwable
     */
    public function update(UpdateEmergencyContactRequest $request, EmergencyContact $emergencyContact): JsonResponse|EmergencyContactResource
    {
        DB::beginTransaction();
        try {

            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $emergencyContact->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($emergencyContact, $changes, Auth::id());
            }

            DB::commit();
            return new EmergencyContactResource($emergencyContact);
        } catch (Exception $exception) {
            Log::error('Update Dependant Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }

    public function show(EmergencyContact $emergencyContact)
    {
        return ApiResponse::success(EmergencyContactResource::make($emergencyContact));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param EmergencyContact $emergencyContact
     * @return JsonResponse|null
     * @throws Throwable
     */
    public function destroy(EmergencyContact $emergencyContact): ?JsonResponse
    {
        DB::beginTransaction();
        try {

            if ($this->isHrAdmin()) {
                $emergencyContact->delete();
            } else {
                app(UpdateApprovalService::class)->delete($emergencyContact, Auth::id());
            }

            DB::commit();

            return ApiResponse::success(null, 'Delete request submitted for approval', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {

            Log::error('Delete EmergencyContact Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
