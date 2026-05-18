<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Http\Requests\StoreCommunityServiceRequest;
use App\Http\Requests\UpdateCommunityServiceRequest;
use App\Http\Resources\CommunityServiceResource;
use App\Models\SelfService\CommunityService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CommunityServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $communityServices = CommunityService::where('employee_id', $request->employeeId)->paginate(10);

        return CommunityServiceResource::collection($communityServices);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreCommunityServiceRequest $request
     *
     * @return CommunityServiceResource|JsonResponse
     */
    public function store(StoreCommunityServiceRequest $request): CommunityServiceResource|JsonResponse
    {
        DB::beginTransaction();
        try {
            $request['user_id'] = Auth::user()->id;

            $communityServices = CommunityService::create(Helper::formatDate($request));
            DB::commit();

            return new CommunityServiceResource($communityServices);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Update a specified resource
     *
     * @param UpdateCommunityServiceRequest $request
     * @param $id
     *
     * @return CommunityServiceResource|JsonResponse
     */
    public function update(UpdateCommunityServiceRequest $request, $id): JsonResponse|CommunityServiceResource
    {
        DB::beginTransaction();
        try {
            $request['dob'] = $request->dob !== 'null' ? Carbon::parse($request->dob)->format('Y-m-d') : null;
            $communityServices = CommunityService::findOrFail($id);
            $communityServices->update($request->all());
            DB::commit();

            return new CommunityServiceResource($communityServices);
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
     *
     * @return JsonResponse|null
     */
    public function destroy($id): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $communityServices = CommunityService::findOrFail($id);
            $communityServices->delete();

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
