<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmergencyContactRequest;
use App\Http\Requests\UpdateEmergencyContactRequest;
use App\Http\Resources\EmergencyContactResource;
use App\Models\ActivityLog;
use App\Models\EmergencyContact;
use App\Models\Employee;
use App\Traits\InformationUpdate;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EmergencyContactController extends Controller
{
    use InformationUpdate;

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $emergencyContacts = EmergencyContact::query();

        $employee = Employee::query()->where('uuid', $request->employeeId)->first();
        $emergencyContacts->where('employee_id', $employee->id);

        return EmergencyContactResource::collection($emergencyContacts->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreEmergencyContactRequest $request
     * @return EmergencyContactResource|JsonResponse
     */
    public function store(StoreEmergencyContactRequest $request): EmergencyContactResource|JsonResponse
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $employee = Employee::query()->where('uuid', $request->employee_id)->first();

            if ($this->isHrAdmin()) {
                $request['user_id'] = $user->id;
                $contact = $employee->emergencyContacts()->create($request->all());
            } else {
                $contact = $employee->emergencyContacts()->create([
                    'user_id' => $user->id
                ]);
                $this->infoDifference($contact, $request->all());
                $this->requestUpdate($contact);
            }

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' added emergency contact for ' . $employee->name,
                'updated', [''], 'emergency-contact')
                ->to($employee)
                ->as($user);

            DB::commit();

            return new EmergencyContactResource($contact);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param UpdateEmergencyContactRequest $request
     * @param $id
     * @return EmergencyContactResource|JsonResponse
     */
    public function update(UpdateEmergencyContactRequest $request, $id): JsonResponse|EmergencyContactResource
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();

            $emergencyContact = EmergencyContact::findOrFail($id);
            if ($this->isHrAdmin()) {
                $emergencyContact->update($request->all());
                $emergencyContact->save();
            } else {
                $this->infoDifference($emergencyContact, $request->all());
                $this->requestUpdate($emergencyContact);
            }

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' updated the emergency contact for ' . $emergencyContact->employee->name,
                'updated', [''], 'emergency-contact')
                ->to($emergencyContact->employee)
                ->as($user);
            DB::commit();
            return new EmergencyContactResource($emergencyContact);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse|null
     */
    public function destroy($id): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $emergencyContact = EmergencyContact::findOrFail($id);
            $emergencyContact->informationUpdate()->delete();
            $emergencyContact->delete();
            DB::commit();
            return response()->json([
                'message' => 'Emergency Contact Deleted'
            ]);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }
}
