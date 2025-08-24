<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDependantRequest;
use App\Http\Requests\UpdateDependantRequest;
use App\Http\Resources\DependantResource;
use App\Models\ActivityLog;
use App\Models\Dependant;
use App\Models\Employee;
use App\Traits\InformationUpdate;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DependantController extends Controller
{
    use InformationUpdate;

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $dependants = Dependant::where('employee_id', $request->employeeId)->paginate(10);

        return DependantResource::collection($dependants);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreDependantRequest $request
     * @return DependantResource|JsonResponse
     */
    public function store(StoreDependantRequest $request): DependantResource|JsonResponse
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();

            $employee = Employee::findOrFail($request->employee_id);

            $request['dob'] = $request->dob != null ? Carbon::parse($request->dob)->format('Y-m-d') : null;

            if ($this->isHrAdmin()) {
                $request['user_id'] = $user->id;
                $dependant = $employee->departments()->create($request->all());
            } else {
                $dependant = $employee->departments()->create([
                    'user_id' => $user->id
                ]);

                $this->infoDifference($dependant, $request->all());
                $this->requestUpdate($dependant);
            }

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' added a dependant for ' . $employee->name,
                'updated dependant', [''], 'dependant')
                ->to($employee)
                ->as($user);

            DB::commit();

            return new DependantResource($dependant);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param UpdateDependantRequest $request
     * @param $id
     * @return DependantResource|JsonResponse
     */
    public function update(UpdateDependantRequest $request, $id): JsonResponse|DependantResource
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();

            $request['dob'] = $request->dob !== 'null' ? Carbon::parse($request->dob)->format('Y-m-d') : null;

            $dependant = Dependant::findOrFail($id);

            if ($this->isHrAdmin()) {
                $dependant->update($request->all());
                $dependant->save();
            } else {
                $this->infoDifference($dependant, $request->all());
                $this->requestUpdate($dependant);
            }

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' updated dependant for ' . $dependant->employee->name,
                'updated dependant', [''], 'dependant')
                ->to($dependant->employee)
                ->as($user);

            DB::commit();

            return new DependantResource($dependant);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse|null
     */
    public function destroy($id): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();

            $dependant = Dependant::findOrFail($id);
            $dependant->informationUpdate()->delete();
            $dependant->delete();

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' deleted dependant for ' . $dependant->employee->name,
                'delete', [''], 'dependant')
                ->to($dependant->employee)
                ->as($user);

            DB::commit();

            return response()->json([
                'message' => 'Emergency Contact Deleted'
            ]);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }
}
