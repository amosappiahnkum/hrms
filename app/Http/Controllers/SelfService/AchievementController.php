<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAchievementRequest;
use App\Http\Requests\UpdateAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\InformationUpdate;
use App\Models\SelfService\Achievement;
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

class AchievementController extends Controller
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
        $achievements = Achievement::query();

        $achievements->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('year');

        $collection = AchievementResource::collection($achievements->paginate($request->per_page ?? 10));

        $pending = [];
        if ($request->employee_uuid) {
            $employee = Employee::where('uuid', $request->employee_uuid)->first();
            if ($employee) {
                $pending = InformationUpdate::where('information_type', 'Achievement')
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

    public function store(StoreAchievementRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            if ($this->isHrAdmin()) {
                Achievement::create($validated);
            } else {
                app(UpdateApprovalService::class)->create(new Achievement(), $validated, Auth::id());
            }

            return ApiResponse::success(null, 'Achievement creation request submitted for approval');
        } catch (Throwable $e) {
            Log::error('Add Achievement Error', ['error' => $e]);
            return ApiResponse::error('Something went wrong');
        }
    }

    public function update(UpdateAchievementRequest $request, Achievement $achievement): JsonResponse|AchievementResource
    {
        DB::beginTransaction();
        try {
            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $achievement->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($achievement, $changes, Auth::id());
            }

            DB::commit();
            return new AchievementResource($achievement);
        } catch (Exception $exception) {
            Log::error('Update Achievement Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }

    public function show(Achievement $achievement)
    {
        return ApiResponse::success(AchievementResource::make($achievement));
    }

    public function destroy(Achievement $achievement): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $achievement->delete();
            } else {
                app(UpdateApprovalService::class)->delete($achievement, Auth::id());
            }

            DB::commit();
            return ApiResponse::success(null, 'Delete request submitted for approval', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {
            Log::error('Delete Achievement Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
