<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Config\Department;
use App\Models\SelfService\Employee;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DepartmentController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $departments = Department::withCount('children');

        if ($request->filled('search')) {
            $departments->where('name', 'LIKE', "%{$request->query('search')}%");
        }

        if ($request->filled('parent_id')) {
            if ($request->parent_id === 'root') {
                $departments->whereNull('parent_department_id');
            } else {
                $departments->whereHas('parent', fn($q) => $q->where('uuid', $request->parent_id));
            }
        }

        return DepartmentResource::collection($departments->paginate($request->per_page ?? 10));
    }

    public function searchDepartments(Request $request): AnonymousResourceCollection
    {
        $departments = Department::query();

        if ($request->filled('query')) {
            $departments->where('name', 'LIKE', "%{$request->query('query')}%");
        }

        return DepartmentResource::collection($departments->paginate(10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreDepartmentRequest $request
     * @return DepartmentResource|JsonResponse
     * @throws Throwable
     */
    public function store(StoreDepartmentRequest $request)
    {
        DB::beginTransaction();

        try {

            $data = $request->validated();

            // Resolve parent department UUID → ID
            if (!empty($data['parent_department_id'])) {
                $parent = Department::where('uuid', $data['parent_department_id'])
                    ->firstOrFail();

                $data['parent_department_id'] = $parent->id;
            } else {
                $data['parent_department_id'] = null;
            }

            $head = null;

            // Resolve HOD if provided
            if (!empty($request->hod)) {

                $head = Employee::where('uuid', $request->hod)->firstOrFail();

                // Employee must have a user account
                if (empty($head->userAccount)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => "{$head->name} must login to complete their profile before being assigned as HOD."
                    ], 400);
                }

                $data['hod'] = $head->id;
            }

            // Create department
            $department = Department::create($data);

            // Assign employee to department and HOD role
            if ($head) {

                $head->update([
                    'department_id' => $department->id
                ]);

                if (!$head->userAccount->hasRole('hod')) {
                    $head->userAccount->assignRole('hod');
                }
            }

            DB::commit();

            return new DepartmentResource($department->fresh()->loadCount('children'));

        } catch (Exception $exception) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage()
            ], 400);
        }
    }


    /**
     * Update the specified resource in storage.
     *
     * @param UpdateDepartmentRequest $request
     * @param string $uuid
     * @return DepartmentResource|JsonResponse
     * @throws Throwable
     */
    public function update(UpdateDepartmentRequest $request, string $uuid)
    {
        DB::beginTransaction();

        try {

            $department = Department::where('uuid', $uuid)->firstOrFail();

            $data = $request->validated();

            // Resolve parent department UUID → ID
            if (!empty($data['parent_department_id'])) {
                $parent = Department::where('uuid', $data['parent_department_id'])
                    ->where('id', '!=', $department->id)
                    ->firstOrFail();
                $data['parent_department_id'] = $parent->id;
            } else {
                $data['parent_department_id'] = null;
            }

            // Only process HOD if provided in request
            if (!empty($request->hod)) {

                $head = Employee::where('uuid', $request->hod)->firstOrFail();

                // Employee must have user account
                if (empty($head->userAccount)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "$head->name must login to complete their profile before being assigned as HOD."
                    ], 400);
                }

                // Strip hod role from previous HOD if they changed and they don't head another department
                $previousHod = $department->headOfDepartment;
                if ($previousHod && $previousHod->id !== $head->id) {
                    $isHodElsewhere = Department::where('hod', $previousHod->id)
                        ->where('id', '!=', $department->id)
                        ->exists();

                    if (!$isHodElsewhere && $previousHod->userAccount?->hasRole('hod')) {
                        $previousHod->userAccount->removeRole('hod');
                    }
                }

                // Update department HOD
                $data['hod'] = $head->id;

                // Assign department to employee
                $head->update([
                    'department_id' => $department->id
                ]);

                // Assign role only if not already assigned
                if (!$head->userAccount->hasRole('hod')) {
                    $head->userAccount->assignRole('hod');
                }
            }

            $department->update($data);

            DB::commit();

            return new DepartmentResource($department->fresh()->loadCount('children'));

        } catch (Exception $exception) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Department $department
     * @return JsonResponse
     */
    public function destroy(Department $department)
    {
        $totalEmployees = $department->employees()->count();

        if ($totalEmployees > 0) {
            return response()->json([
                'success' => false,
                'message' => "You can't delete this department because it has employees."
            ], 400);
        }

        $uuid = $department->uuid;


        $department->delete();

        return response()->json(['id' => $uuid]);
    }
}
