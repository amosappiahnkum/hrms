<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateContactDetailRequest;
use App\Http\Resources\ContactDetailResource;
use App\Models\ActivityLog;
use App\Models\ContactDetail;
use App\Models\Employee;
use App\Traits\InformationUpdate;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContactDetailController extends Controller
{
    use InformationUpdate;

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
                $this->requestUpdate($contactDetail);
            }

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' updated the contact details for ' . $contactDetail->employee->name,
                'updated contact details', [''], 'contact-details')
                ->to($contactDetail->employee)
                ->as($user);

            DB::commit();
            return new ContactDetailResource($contactDetail);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }
}
