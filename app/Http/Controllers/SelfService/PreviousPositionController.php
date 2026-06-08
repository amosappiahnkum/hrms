<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePreviousPositionRequest;
use App\Http\Requests\UpdatePreviousPositionRequest;
use App\Http\Resources\PreviousPositionResource;
use App\Models\InformationUpdate;
use App\Models\SelfService\Employee;
use App\Models\Training\PreviousPosition;
use App\Services\UpdateApprovalService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Throwable;

class PreviousPositionController extends Controller
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
        $previousPositions = PreviousPosition::query();

        $previousPositions->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('start');

        $collection = PreviousPositionResource::collection($previousPositions->paginate($request->per_page ?? 10));

        $pending = [];
        if ($request->employee_uuid) {
            $employee = Employee::where('uuid', $request->employee_uuid)->first();
            if ($employee) {
                $pending = InformationUpdate::where('information_type', 'PreviousPosition')
                    ->where('type', 'create')
                    ->where('status', 'pending')
                    ->where('new_info->employee_id', $employee->id)
                    ->get()
                    ->map(fn($update) => array_merge($update->new_info, ['uuid' => $update->uuid, 'pending' => true]))
                    ->values();
            }
        }

        return $collection->additional(['pending' => $pending]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StorePreviousPositionRequest $request
     * @return PreviousPositionResource|JsonResponse
     * @throws \Throwable
     */
    public function store(StorePreviousPositionRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            if ($this->isHrAdmin()) {
                PreviousPosition::create($validated);
            } else {
                app(UpdateApprovalService::class)->create(new PreviousPosition(), $validated, Auth::id());
            }

            return ApiResponse::success(null, 'Position creation request submitted for approval');
        } catch (Throwable $e) {
            Log::error('Add PreviousPosition Error', ['error' => $e]);
            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param UpdatePreviousPositionRequest $request
     * @param PreviousPosition $previousPosition
     * @return PreviousPositionResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdatePreviousPositionRequest $request, PreviousPosition $previousPosition): JsonResponse|PreviousPositionResource
    {
        DB::beginTransaction();
        try {
            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $previousPosition->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($previousPosition, $changes, Auth::id());
            }

            DB::commit();
            return new PreviousPositionResource($previousPosition);
        } catch (Exception $exception) {
            Log::error('Update PreviousPosition Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }

    public function show(PreviousPosition $previousPosition)
    {
        return ApiResponse::success(PreviousPositionResource::make($previousPosition));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param PreviousPosition $previousPosition
     * @return JsonResponse|null
     * @throws \Throwable
     */
    public function destroy(PreviousPosition $previousPosition): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $previousPosition->delete();
            } else {
                app(UpdateApprovalService::class)->delete($previousPosition, Auth::id());
            }

            DB::commit();
            return ApiResponse::success(null, 'Delete request submitted for approval', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {
            Log::error('Delete PreviousPosition Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
