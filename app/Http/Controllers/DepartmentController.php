<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Employee;
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
     */
    public function update(UpdateDepartmentRequest $request, string $uuid)
    {
        DB::beginTransaction();
        try {
            $department = Department::where('uuid', $uuid)->firstOrFail();
            $head = Employee::where('uuid', $request->hod)->firstOrFail();

            $data = $request->validated();

            $data['hod'] = $head->id;

            $head->update([
                'department_id' => $department->id
            ]);

            $department->update($data);

            $head->userAccount->assignRole('hod');

            DB::commit();
            return new DepartmentResource($department->fresh());
        }catch (\Exception $exception){
            DB::rollBack();

            return response()->json(['success' => false, 'message' => $exception->getMessage()], 400);
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
