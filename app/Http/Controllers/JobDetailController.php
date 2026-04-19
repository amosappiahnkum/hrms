<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\UpdateJobDetailRequest;
use App\Http\Resources\JobDetailResource;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\JobDetail;
use App\Models\PreviousPosition;
use App\Traits\InformationUpdate;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * @return JobDetailResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateJobDetailRequest $request, Employee $employee): JobDetailResource|JsonResponse
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $employee->jobDetail->update($request->validated());
                $employee->jobDetail->save();
            } else {
                $this->infoDifference($employee->jobDetail, $request->validated());
                $this->requestUpdate($employee->jobDetail);
            }

            /*         if ($request->has('position_id') && $request->position_id != 'null') {
                         PreviousPosition::updateOrCreate([
                             'position_id' => $request->position_id,
                             'employee_id' => $jobDetail->employee_id
                         ], [
                             'position_id' => $request->position_id,
                             'employee_id' => $jobDetail->employee_id,
                             'user_id' => Auth::id()
                         ]);
                     }*/

            DB::commit();

            return ApiResponse::success(JobDetailResource::make($employee->jobDetail));
        } catch (Exception $exception) {
            Log::error('Job Detail Update: ', [$exception]);
            Db::rollBack();
            return ApiResponse::error('Something went wrong', []);
        }
    }

}
