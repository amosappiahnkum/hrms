<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveTypeRequest;
use App\Http\Requests\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\Config\LeaveType;
use App\Models\Config\LeaveTypeLevelConfig;
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

        $config->update($request->validate([
            'number_of_days' => ['sometimes', 'required', 'integer', 'min:0'],
            'maximum_consecutive_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'should_request_before' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'allow_half_day' => ['sometimes', 'boolean'],
            'allow_carry_forward' => ['sometimes', 'boolean'],
            // Most unused days carried into the next leave year; empty = all of them.
            'max_carry_forward_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]));

        return new LeaveTypeResource($config->leaveType);
    }
}
