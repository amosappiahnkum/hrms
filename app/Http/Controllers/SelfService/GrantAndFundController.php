<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGrantAndFundRequest;
use App\Http\Requests\UpdateGrantAndFundRequest;
use App\Http\Resources\GrantAndFundResource;
use App\Models\InformationUpdate;
use App\Models\SelfService\Employee;
use App\Models\SelfService\GrantAndFund;
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

class GrantAndFundController extends Controller
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
        $grantAndFunds = GrantAndFund::query()->with('dynamicValues.field');

        $grantAndFunds->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('start');


        $collection = GrantAndFundResource::collection($grantAndFunds->paginate($request->per_page ?? 10));

        $pending = [];
        if ($request->employee_uuid) {
            $employee = Employee::where('uuid', $request->employee_uuid)->first();
            if ($employee) {
                $pending = InformationUpdate::where('information_type', 'GrantAndFund')
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

    public function store(StoreGrantAndFundRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            if ($this->isHrAdmin()) {
                GrantAndFund::create($validated);
            } else {
                app(UpdateApprovalService::class)->create(new GrantAndFund(), $validated, Auth::id());
            }

            return ApiResponse::success(null, 'Grant creation request submitted for approval');
        } catch (Throwable $e) {
            Log::error('Add GrantAndFund Error', ['error' => $e]);
            return ApiResponse::error('Something went wrong');
        }
    }

    public function update(UpdateGrantAndFundRequest $request, GrantAndFund $grant): JsonResponse|GrantAndFundResource
    {
        DB::beginTransaction();
        try {
            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $grant->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($grant, $changes, Auth::id());
            }

            DB::commit();
            return new GrantAndFundResource($grant->load('dynamicValues.field'));
        } catch (Exception $exception) {
            Log::error('Update GrantAndFund Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }

    public function show(GrantAndFund $grant)
    {
        return ApiResponse::success(GrantAndFundResource::make($grant->load('dynamicValues.field')));
    }

    public function destroy(GrantAndFund $grant): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $grant->delete();
            } else {
                app(UpdateApprovalService::class)->delete($grant, Auth::id());
            }

            DB::commit();
            return ApiResponse::success(null, 'Delete request submitted for approval', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {
            Log::error('Delete GrantAndFund Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
