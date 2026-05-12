<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateJobDetailRequest;
use App\Http\Resources\JobDetailResource;
use App\Models\SelfService\Employee;
use App\Services\UpdateApprovalService;
use App\Traits\InformationUpdate;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class JobDetailController extends Controller
{
    use InformationUpdate;

    protected string $docPath = 'docs/job_contract';

    protected array $allowedFiles = ['pdf'];

    /**
     * Display the specified resource.
     *
     * @param Employee $employee
     * @return JsonResponse
     */
    public function show(Employee $employee): JsonResponse
    {
        return ApiResponse::success(JobDetailResource::make($employee->jobDetail));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateJobDetailRequest $request
     * @param Employee $employee
     * @return JsonResponse
     * @throws Throwable
     */

    public function update(UpdateJobDetailRequest $request, Employee $employee): JsonResponse
    {
        DB::beginTransaction();

        $jobDetail = $employee->jobDetail;
        try {

            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $jobDetail->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($jobDetail, $changes, Auth::id());
            }

            DB::commit();
            return ApiResponse::success([]);
        } catch (Exception $exception) {
            Log::error('Update Job Detail Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }
}
