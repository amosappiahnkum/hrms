<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Helper;
use App\Http\Requests\UpdateContactDetailRequest;
use App\Http\Resources\ContactDetailResource;
use App\Models\SelfService\Employee;
use App\Services\Employee\WorkEmail;
use App\Services\UpdateApprovalService;
use App\Traits\InformationUpdate;
use App\Traits\Notifier;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

            // The work email is the login email: HR changes it, through WorkEmail so both stay in step.
            $workEmail = app(WorkEmail::class);
            $newWorkEmail = $changes['work_email'] ?? null;
            unset($changes['work_email']);

            if (array_key_exists('work_email', $request->validated()) && $workEmail->differs($employee, $newWorkEmail)) {
                if (!$this->can('edit-employee')) {
                    throw ValidationException::withMessages(['work_email' => 'Only HR can change your work email.']);
                }
                if ($newWorkEmail) {
                    $workEmail->change($employee, $newWorkEmail);
                } elseif ($employee->userAccount) {
                    throw ValidationException::withMessages(['work_email' => 'The work email is this employee\'s login and cannot be removed.']);
                } else {
                    $changes['work_email'] = null;
                }
            }

            if ($this->can('edit-employee')) {
                $contact->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($contact, $changes, Auth::id());
            }
//            Helper::updateSRMS($request->staff_id, $contact?->work_telephone);
            DB::commit();
            return ApiResponse::success([]);
        } catch (Exception $exception) {
            DB::rollBack();
            return ApiResponse::fromException($exception);
        }
    }
}
