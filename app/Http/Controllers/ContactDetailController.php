<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Helper;
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
        Log::info('here');
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
     * @return ContactDetailResource|JsonResponse
     * @throws Throwable
     */
    public function update(UpdateContactDetailRequest $request, Employee $employee): JsonResponse|ContactDetailResource
    {
        DB::beginTransaction();

        try {
            if ($this->isHrAdmin()) {
                $employee->contactDetail->update($request->validated());
                $employee->contactDetail->save();
            } else {
                $this->infoDifference($employee->contactDetail, $request->validated());
                $this->requestUpdate($employee->contactDetail);

                Helper::updateSRMS($this->cleanPhoneNumber($request->telephone));
            }

            DB::commit();
            return ApiResponse::success(ContactDetailResource::make($employee->contactDetail));
        } catch (Exception $exception) {
            DB::rollBack();

            Log::error($exception);
            return ApiResponse::error('Something went wrong', []);
        }
    }
}
