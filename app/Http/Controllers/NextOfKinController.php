<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\UpdateNextOfKinRequest;
use App\Http\Resources\NextOfKinResource;
use App\Models\Employee;
use App\Models\NextOfKin;
use App\Traits\InformationUpdate;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NextOfKinController extends Controller
{
    use InformationUpdate;

    /**
     * @param Employee $employee
     * @return JsonResponse
     */
    public function show(Employee $employee)
    {
        if (!$employee->nextOfKin) {
            $employee->nextOfKin()->create();
        }

        return ApiResponse::success(NextOfKinResource::make($employee->nextOfKin)) ;
    }

    /**
     * @param UpdateNextOfKinRequest $request
     * @param Employee $employee
     * @return NextOfKinResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateNextOfKinRequest $request, Employee $employee): NextOfKinResource|JsonResponse
    {
        DB::beginTransaction();

        try {
            if ($this->isHrAdmin()) {
                $employee->nextOfKin->update($request->validated());
                $employee->nextOfKin->save();
            } else {
                $this->infoDifference($employee->nextOfKin, $request->validated());
                $this->requestUpdate($employee->nextOfKin);
            }

            DB::commit();

            return ApiResponse::success(NextOfKinResource::make($employee->nextOfKin));
        } catch (Exception $exception) {
            Log::error('Next of Kin Update: ', [$exception]);
            return ApiResponse::error('Something went wrong', []);
        }
    }
}
