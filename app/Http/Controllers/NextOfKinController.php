<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNextOfKinRequest;
use App\Http\Resources\NextOfKinResource;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Traits\InformationUpdate;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NextOfKinController extends Controller
{
    use InformationUpdate;

    public function show($employeeId): NextOfKinResource
    {
        $employee = Employee::query()->where('uuid', $employeeId)->first();

        if (!$employee->nextOfKin) {
            $nextOfKin = $employee->nextOfKin()->create();
        }

        return new NextOfKinResource($employee->nextOfKin ?? $nextOfKin);
    }

    /**
     * @param UpdateNextOfKinRequest $request
     * @param $id
     *
     * @return NextOfKinResource|JsonResponse
     */
    public function update(UpdateNextOfKinRequest $request, $id): NextOfKinResource|JsonResponse
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();

            $employee = Employee::findOrFail($request->employee_id);

            $nextOfKin = $employee->nextOfKin;

            if (!$nextOfKin) {
                $nextOfKin = $employee->nextOfKin()->create();
            }

            if ($this->isHrAdmin()) {
                $nextOfKin->update($request->all());
                $nextOfKin->save();
            } else {
                $this->infoDifference($nextOfKin, $request->all());
                $this->requestUpdate($nextOfKin);
            }

            $employee = Employee::findOrFail($request->employee_id);

            ActivityLog::add(($user?->employee?->name ?? $user->username) . 'update the next of kin details for ' . $employee->name,
                'updated', [''], 'next of kin')
                ->to($nextOfKin)
                ->as($user);

            DB::commit();

            return new NextOfKinResource($nextOfKin);
        } catch (Exception $exception) {
            Log::error('Next of Kin Update: ', [$exception]);

            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }
}
