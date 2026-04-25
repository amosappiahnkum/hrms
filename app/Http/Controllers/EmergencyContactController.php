<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreEmergencyContactRequest;
use App\Http\Requests\UpdateEmergencyContactRequest;
use App\Http\Resources\EmergencyContactResource;
use App\Models\EmergencyContact;
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

class EmergencyContactController extends Controller
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
     * @throws \Throwable
     */
    public function store(StoreEmergencyContactRequest $request): JsonResponse|EmergencyContactResource
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();

            if ($this->isHrAdmin()) {
                $emergencyContact = EmergencyContact::create($request->validated());
            } else {
                $emergencyContact = EmergencyContact::create(['user_id' => $user->id]);

                $this->infoDifference($emergencyContact, $request->validated());
                $this->requestUpdate($emergencyContact);
            }

            DB::commit();
            return new EmergencyContactResource($emergencyContact);
        } catch (Exception $exception) {
            Log::error('Add EmergencyContact Error: ', [$exception]);

            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateEmergencyContactRequest $request
     * @param EmergencyContact $emergencyContact
     * @return EmergencyContactResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateEmergencyContactRequest $request, EmergencyContact $emergencyContact): JsonResponse|EmergencyContactResource
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $emergencyContact->update($request->all());
                $emergencyContact->save();
            } else {
                $this->infoDifference($emergencyContact, $request->all());
                $this->requestUpdate($emergencyContact);
            }


            DB::commit();
            return new EmergencyContactResource($emergencyContact);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    public function show(EmergencyContact $emergencyContact)
    {
        Log::info('osikani', [$emergencyContact]);
        return ApiResponse::success(EmergencyContactResource::make($emergencyContact));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param EmergencyContact $emergencyContact
     * @return JsonResponse|null
     * @throws \Throwable
     */
    public function destroy(EmergencyContact $emergencyContact): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $emergencyContact->informationUpdate()->delete();
            $emergencyContact->delete();

            DB::commit();

            return ApiResponse::success(null, 'EmergencyContact deleted.', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {

            Log::error('Delete EmergencyContact Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
