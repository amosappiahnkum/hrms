<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAffiliationRequest;
use App\Http\Requests\UpdateAffiliationRequest;
use App\Http\Resources\AffiliationResource;
use App\Models\InformationUpdate;
use App\Models\SelfService\Affiliation;
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

class AffiliationController extends Controller
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
        $affiliations = Affiliation::query();

        $affiliations->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('start');

        $collection = AffiliationResource::collection($affiliations->paginate($request->per_page ?? 10));

        $pending = [];
        if ($request->employee_uuid) {
            $employee = Employee::where('uuid', $request->employee_uuid)->first();
            if ($employee) {
                $pending = InformationUpdate::where('information_type', 'Affiliation')
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

    public function store(StoreAffiliationRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            if ($this->isHrAdmin()) {
                Affiliation::create($validated);
            } else {
                app(UpdateApprovalService::class)->create(new Affiliation(), $validated, Auth::id());
            }

            return ApiResponse::success(null, 'Affiliation creation request submitted for approval');
        } catch (Throwable $e) {
            Log::error('Add Affiliation Error', ['error' => $e]);
            return ApiResponse::error('Something went wrong');
        }
    }

    public function update(UpdateAffiliationRequest $request, Affiliation $affiliation): JsonResponse|AffiliationResource
    {
        DB::beginTransaction();
        try {
            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $affiliation->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($affiliation, $changes, Auth::id());
            }

            DB::commit();
            return new AffiliationResource($affiliation);
        } catch (Exception $exception) {
            Log::error('Update Affiliation Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }

    public function show(Affiliation $affiliation)
    {
        return ApiResponse::success(AffiliationResource::make($affiliation));
    }

    public function destroy(Affiliation $affiliation): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $affiliation->delete();
            } else {
                app(UpdateApprovalService::class)->delete($affiliation, Auth::id());
            }

            DB::commit();
            return ApiResponse::success(null, 'Delete request submitted for approval', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {
            Log::error('Delete Affiliation Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
