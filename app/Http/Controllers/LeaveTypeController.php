<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveTypeRequest;
use App\Http\Requests\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use App\Models\LeaveTypeLevelConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LeaveTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return AnonymousResourceCollection
     */
    public function index(): AnonymousResourceCollection
    {
        $leaveTypesQuery = LeaveType::query();

        return LeaveTypeResource::collection($leaveTypesQuery->paginate(10));
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param StoreLeaveTypeRequest $request
     * @return Response
     */
    public function store(StoreLeaveTypeRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param LeaveType $leaveType
     *
     * @return Response
     */
    public function show(LeaveType $leaveType)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param LeaveType $leaveType
     *
     * @return Response
     */
    public function edit(LeaveType $leaveType)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateLeaveTypeRequest $request
     * @param LeaveType  $leaveType
     *
     * @return Response
     */
    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  LeaveType  $leaveType
     *
     * @return Response
     */
    public function destroy(LeaveType $leaveType)
    {
        //
    }

    /**
     * @param Request $request
     * @param string $leaveTypeId
     * @return LeaveTypeResource
     */
    public function updateLeaveTypeConfig(Request $request, string $leaveTypeId): LeaveTypeResource
    {
        $config = LeaveTypeLevelConfig::query()->where('uuid', $leaveTypeId)->firstOrFail();

        $config->update($request->all());

        return new LeaveTypeResource($config->leaveType);
    }
}
