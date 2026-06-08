<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAwardRequest;
use App\Http\Requests\UpdateAwardRequest;
use App\Http\Resources\AwardResource;
use App\Models\InformationUpdate;
use App\Models\SelfService\Award;
use App\Models\SelfService\Employee;
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

class AwardController extends Controller
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
        $awards = Award::query();

        $awards->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('year');

        $collection = AwardResource::collection($awards->paginate($request->per_page ?? 10));

        $pending = [];
        if ($request->employee_uuid) {
            $employee = Employee::where('uuid', $request->employee_uuid)->first();
            if ($employee) {
                $pending = InformationUpdate::where('information_type', 'Award')
                    ->where('type', 'create')
                    ->where('status', 'pending')
                    ->where('new_info->employee_id', $employee->id)
                    ->get()
                    ->map(fn($u) => array_merge($u->new_info, ['uuid' => $u->uuid, 'pending' => true]))
                    ->values();
            }
        }

        return $collection->additional(['pending' => $pending]);
    }

    public function store(StoreAwardRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            if ($this->isHrAdmin()) {
                Award::create($validated);
            } else {
                app(UpdateApprovalService::class)->create(new Award(), $validated, Auth::id());
            }

            return ApiResponse::success(null, 'Award creation request submitted for approval');
        } catch (Throwable $e) {
            Log::error('Add Award Error', ['error' => $e]);
            return ApiResponse::error('Something went wrong');
        }
    }

    public function update(UpdateAwardRequest $request, Award $award): JsonResponse|AwardResource
    {
        DB::beginTransaction();
        try {
            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $award->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($award, $changes, Auth::id());
            }

            DB::commit();
            return new AwardResource($award);
        } catch (Exception $exception) {
            Log::error('Update Award Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }

    public function show(Award $award)
    {
        return ApiResponse::success(AwardResource::make($award));
    }

    public function destroy(Award $award): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $award->delete();
            } else {
                app(UpdateApprovalService::class)->delete($award, Auth::id());
            }

            DB::commit();
            return ApiResponse::success(null, 'Delete request submitted for approval', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {
            Log::error('Delete Award Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
