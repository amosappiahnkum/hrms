<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNextOfKinRequest;
use App\Http\Resources\NextOfKinResource;
use App\Models\Employee;
use App\Services\UpdateApprovalService;
use App\Traits\InformationUpdate;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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
            $nextOfKin = $employee->nextOfKin()->create();
            return ApiResponse::success(NextOfKinResource::make($nextOfKin));
        }

        return ApiResponse::success(NextOfKinResource::make($employee->nextOfKin));
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

        $nextOfKin = $employee->nextOfKin;
        try {

            $changes = $request->validated();

//            if ($this->isHrAdmin()) {
//                $nextOfKin->update($changes);
//            } else {
            app(UpdateApprovalService::class)->update($nextOfKin, $changes, Auth::id());
//            }

            DB::commit();
            return ApiResponse::success([]);
        } catch (Exception $exception) {
            Log::error('Update Next OF Kin Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }
}
