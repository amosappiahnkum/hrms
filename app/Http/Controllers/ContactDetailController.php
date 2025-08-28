<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateContactDetailRequest;
use App\Http\Resources\ContactDetailResource;
use App\Models\ActivityLog;
use App\Models\ContactDetail;
use App\Models\Employee;
use App\Traits\InformationUpdate;
use App\Traits\Notifier;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContactDetailController extends Controller
{
    use InformationUpdate, Notifier;

    /**
     * Display the specified resource.
     *
     * @param $employeeId
     * @return ContactDetailResource
     */
    public function show($employeeId): ContactDetailResource
    {
        $employee = Employee::findOrFail($employeeId);
        return new ContactDetailResource($employee->contactDetail);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateContactDetailRequest $request
     * @param $id
     * @return ContactDetailResource|JsonResponse
     */
    public function update(UpdateContactDetailRequest $request, $id): JsonResponse|ContactDetailResource
    {
        DB::beginTransaction();

        try {
            $user = Auth::user();

            $contactDetail = ContactDetail::findOrFail($id);

            if ($this->isHrAdmin()) {
                $contactDetail->update($request->all());
                $contactDetail->save();
            } else {
                $this->infoDifference($contactDetail, $request->all());
                $update = $this->requestUpdate($contactDetail);

                /*$data = [
                    "title" => "Change in Contact information",
                    "message" => $contactDetail->employee->name . " made a request to change the Contact information"
                ];

                $this->notify($data, $contactDetail->employee_id, [
                    'type' => 'ContactDetail',
                    'model_type' => 'InformationUpdate',
                    'model_id' => $update->id
                ]);*/
            }

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' updated the contact details for ' . $contactDetail->employee->name,
                'updated contact details', [''], 'contact-details')
                ->to($contactDetail->employee)
                ->as($user);

            DB::commit();
            return new ContactDetailResource($contactDetail);
        } catch (Exception $exception) {
            DB::rollBack();

            Log::error($exception);
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }
}
