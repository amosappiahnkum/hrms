<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
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
        $departments = Department::query()
            ->with(['headOfDepartment', 'parent'])
            ->withCount(['children', 'employees'])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->query('search')}%"))
            ->when($request->filled('parent_id'), fn ($q) => $request->parent_id === 'root'
                ? $q->whereNull('parent_department_id')
                : $q->whereHas('parent', fn ($p) => $p->where('uuid', $request->parent_id)))
            ->orderBy('name');

        return DepartmentResource::collection($departments->paginate($request->integer('per_page', 10)));
    }

    /** One department, with its ancestors (`path`, top level first) for breadcrumbs. */
    public function show(string $uuid): DepartmentResource
    {
        $department = Department::with(['headOfDepartment', 'parent'])
            ->withCount(['children', 'employees'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return (new DepartmentResource($department))->additional(['path' => $department->ancestors()
            ->map(fn (Department $d) => ['uuid' => $d->uuid, 'name' => $d->name])
            ->values()]);
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

            activity('departments')->performedOn($department)->log("Created department: {$department->name}");

            DB::commit();

            return new DepartmentResource($department->fresh(['headOfDepartment', 'parent'])->loadCount(['children', 'employees']));

        } catch (Exception $exception) {

            DB::rollBack();

            return ApiResponse::fromException($exception);
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

            // The parent changes only when it is sent: editing the name must not move a sub-department.
            if ($request->exists('parent_department_id')) {
                $parent = !empty($data['parent_department_id'])
                    ? Department::where('uuid', $data['parent_department_id'])->firstOrFail()
                    : null;

                if ($parent && ($parent->id === $department->id || $parent->ancestors()->contains('id', $department->id))) {
                    throw new UserFacingException("{$department->name} can't be placed under itself or one of its sub-departments.");
                }

                $data['parent_department_id'] = $parent?->id;
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

            activity('departments')->performedOn($department)->log("Updated department: {$department->name}");

            DB::commit();

            return new DepartmentResource($department->fresh(['headOfDepartment', 'parent'])->loadCount(['children', 'employees']));

        } catch (Exception $exception) {
            DB::rollBack();

            return ApiResponse::fromException($exception);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Department $department
     * @return JsonResponse
     */
    public function destroy(string $uuid): JsonResponse
    {
        $department = Department::withCount(['children', 'employees'])->where('uuid', $uuid)->firstOrFail();

        if ($department->employees_count > 0) {
            throw new UserFacingException("You can't delete {$department->name} because it has employees.");
        }

        if ($department->children_count > 0) {
            throw new UserFacingException("You can't delete {$department->name} because it has sub-departments. Move or delete them first.");
        }

        $name = $department->name;
        $department->delete();

        activity('departments')->log("Deleted department: {$name}");

        return response()->json(['id' => $uuid]);
    }
}
