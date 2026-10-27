<?php

namespace App\Http\Controllers;

use App\Enums\Statuses;
use App\Helpers\ApiResponse;
use App\Http\Requests\StoreAchievementRequest;
use App\Http\Requests\StoreAffiliationRequest;
use App\Http\Requests\StoreAwardRequest;
use App\Http\Requests\StoreDependantRequest;
use App\Http\Requests\StoreEmergencyContactRequest;
use App\Http\Requests\StoreExperienceRequest;
use App\Http\Requests\StoreGrantAndFundRequest;
use App\Http\Requests\StorePreviousPositionRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\StorePublicationRequest;
use App\Http\Requests\StoreQualificationRequest;
use App\Http\Requests\UpdateInformationUpdateRequest;
use App\Http\Resources\ApprovalDetailResource;
use App\Http\Resources\ApprovalResource;
use App\Http\Resources\InformationUpdateResource;
use App\Models\InformationUpdate;
use App\Models\SelfService\Employee;
use App\Services\UpdateApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InformationUpdateController extends Controller
{
    /**
     * Morph type => the store request used to validate it, for pending "create" requests.
     */
    private const PENDING_CREATE_REQUESTS = [
        'Achievement'      => StoreAchievementRequest::class,
        'Affiliation'      => StoreAffiliationRequest::class,
        'Award'            => StoreAwardRequest::class,
        'Dependant'        => StoreDependantRequest::class,
        'Education'        => StoreQualificationRequest::class,
        'EmergencyContact' => StoreEmergencyContactRequest::class,
        'Experience'       => StoreExperienceRequest::class,
        'GrantAndFund'     => StoreGrantAndFundRequest::class,
        'PreviousPosition' => StorePreviousPositionRequest::class,
        'Project'          => StoreProjectRequest::class,
        'Publication'      => StorePublicationRequest::class,
    ];

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $query = InformationUpdate::query()->latest();

        // 🔎 Filters
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('information_type', 'like', "%{$search}%")
                    ->orWhereHas('requestedBy.employee', function ($q) use ($search) {
                        $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('staff_id', 'like', "%{$search}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$search}%"]);
                    });
            });
        }

        $updates = $query->with([
            'requestedBy:id,employee_id',
            'requestedBy.employee:id,first_name,last_name',
            'reviewedBy:id,employee_id',
            'reviewedBy.employee:id,first_name,last_name',
        ])->paginate($request->per_page ?? 10);

        return ApprovalResource::collection($updates);
    }


    /**
     * @param InformationUpdate $informationUpdate
     *
     * @return JsonResponse
     */
    public function show(InformationUpdate $informationUpdate)
    {
        abort_unless(
            $this->can('approve-employee-update') || (int) $informationUpdate->requested_by === (int) Auth::id(),
            403,
            'You can only view your own update requests.'
        );

        $informationUpdate->load([
            'requestedBy:id,employee_id',
            'requestedBy.employee:id,first_name,last_name',

            'reviewedBy:id,employee_id',
            'reviewedBy.employee:id,first_name,last_name',
        ]);
//        return response()->json($informationUpdate);
        return ApiResponse::success(ApprovalDetailResource::make($informationUpdate));
    }

    /**
     * @param UpdateInformationUpdateRequest $request
     * @param InformationUpdate $informationUpdate
     *
     * @return JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateInformationUpdateRequest $request, InformationUpdate $informationUpdate): JsonResponse
    {
        try {
            DB::beginTransaction();

            $informationUpdate->update([
                'status' => $request->status,
                'status_changed_by' => Auth::id(),
                'status_changed_date' => Carbon::now()->format('Y-m-d')
            ]);

            if ($request->status === Statuses::APPROVED->value) {
                $informationUpdate->information()->update($informationUpdate->new_info);
            }

            DB::commit();

            return response()->json([
                'message' => 'Information update ' . $request->status . ' successfully',
                'status' => $request->status
            ]);
        } catch (\Exception $exception) {
            DB::rollBack();

            Log::error('Information Update Error: ', [$exception]);
            return response()->json([
                'message' => 'Something went wrong'
            ], 400);
        }
    }

    public function approve(InformationUpdate $informationUpdate): JsonResponse
    {
        try {
            // 🔒 Optional but important
//            $this->authorize('approve', $update);

            app(UpdateApprovalService::class)->approve($informationUpdate, Auth::id());

            activity('approvals')->performedOn($informationUpdate)
                ->log("Approved {$informationUpdate->type} request for " . class_basename($informationUpdate->information_type));

            return ApiResponse::success(
                null,
                'Approval successful'
            );

        } catch (\Throwable $e) {
            Log::error('Approval failed', [
                'update_id' => $informationUpdate->uuid,
                'error' => $e
            ]);

            return ApiResponse::fromException($e, 'Unable to approve request');
        }
    }

    public function reject(Request $request, InformationUpdate $informationUpdate): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {

            // 🔒 Optional but important
//            $this->authorize('approve', $update);

            app(UpdateApprovalService::class)
                ->reject(
                    $informationUpdate,
                    Auth::id(),
                    $request->reason
                );

            activity('approvals')->performedOn($informationUpdate)
                ->withProperties(['reason' => $request->reason])
                ->log("Rejected {$informationUpdate->type} request for " . class_basename($informationUpdate->information_type));

            return ApiResponse::success(
                null,
                'Request rejected'
            );

        } catch (\Throwable $e) {
            Log::error('Rejection failed', [
                'update_id' => $informationUpdate->uuid,
                'error' => $e
            ]);

            return ApiResponse::fromException($e, 'Unable to reject request');
        }
    }

    public function myRequest(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $query = InformationUpdate::query()
            ->with(['information']) // load target model if exists
            ->where('requested_by', Auth::id())
            ->latest();

        // 🔎 Filters
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        $updates = $query->paginate($request->per_page ?? 10);

        return ApprovalDetailResource::collection($updates);
    }

    /**
     * Let the requester correct a new record that is still awaiting approval.
     * The record's own store request validates the data, so the rules match creating it.
     *
     * @throws \Throwable
     */
    public function updatePending(Request $request, InformationUpdate $informationUpdate): JsonResponse
    {
        abort_unless(
            (int) $informationUpdate->requested_by === (int) Auth::id(),
            403,
            'You can only edit your own update requests.'
        );

        $requestClass = self::PENDING_CREATE_REQUESTS[$informationUpdate->information_type] ?? null;

        if (!$requestClass) {
            return ApiResponse::error('This request cannot be edited.', null, 422);
        }

        // The record stays with the employee it was requested for.
        $employee = Employee::find($informationUpdate->new_info['employee_id'] ?? null);

        if (!$employee) {
            return ApiResponse::error('This request cannot be edited.', null, 422);
        }

        $request->merge(['employee_uuid' => $employee->uuid]);

        try {
            $validated = app($requestClass)->validated();

            $update = app(UpdateApprovalService::class)->amendCreate($informationUpdate, $validated, Auth::id());

            activity('approvals')->performedOn($update)
                ->log('Edited pending create request for ' . class_basename($update->information_type));

            return ApiResponse::success(null, 'Request updated');
        } catch (\Throwable $e) {
            return ApiResponse::fromException($e);
        }
    }

}
