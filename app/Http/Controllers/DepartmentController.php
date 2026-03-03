<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
        $departments = Department::query();

        if ($request->filled('search')) {
            $search = $request->query('search');
            $departments->where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%");
            });
        }
        return DepartmentResource::collection($departments->paginate($request->per_page ?? 10));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreDepartmentRequest $request
     * @return Response
     */
    public function store(StoreDepartmentRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param Department $department
     * @return Response
     */
    public function show(Department $department)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param Department $department
     * @return Response
     */
    public function edit(Department $department)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateDepartmentRequest $request
     * @param string $uuid
     * @return DepartmentResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateDepartmentRequest $request, string $uuid)
    {
        DB::beginTransaction();

        try {
            $department = Department::where('uuid', $uuid)->firstOrFail();
            $data = $request->validated();

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

            return new DepartmentResource($department->fresh());

        } catch (\Exception $exception) {
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
     * @return Response
     */
    public function destroy(Department $department)
    {
        //
    }
}
