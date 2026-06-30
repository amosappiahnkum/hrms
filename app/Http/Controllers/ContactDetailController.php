<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Helper;
use App\Http\Requests\UpdateContactDetailRequest;
use App\Http\Resources\ContactDetailResource;
use App\Models\SelfService\Employee;
use App\Services\UpdateApprovalService;
use App\Traits\InformationUpdate;
use App\Traits\Notifier;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ContactDetailController extends Controller
{
    use InformationUpdate, Notifier;

    /**
     * Display the specified resource.
     *
     * @param Employee $employee
     * @return JsonResponse
     */
    public function show(Employee $employee): JsonResponse
    {
        return ApiResponse::success(ContactDetailResource::make($employee->contactDetail));
    }

    function cleanPhoneNumber($phone): string
    {
        // Check if phone starts with '0'
        if (substr($phone, 0, 1) === '0') {
            return substr($phone, 1); // Remove the first digit
        }

        return $phone; // Return as-is
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateContactDetailRequest $request
     * @param Employee $employee
     * @return JsonResponse
     * @throws Throwable
     */

    public function update(UpdateContactDetailRequest $request, Employee $employee)
    {
        DB::beginTransaction();

        $contact = $employee->contactDetail;
        try {

            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $contact->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($contact, $changes, Auth::id());
            }
//            Helper::updateSRMS($request->staff_id, $contact?->work_telephone);
            DB::commit();
            return ApiResponse::success([]);
        } catch (Exception $exception) {
            Log::error('Update Contact Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }
}
