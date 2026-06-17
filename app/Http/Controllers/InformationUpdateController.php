<?php

namespace App\Http\Controllers;

use App\Enums\Statuses;
use App\Helpers\ApiResponse;
use App\Http\Requests\UpdateInformationUpdateRequest;
use App\Http\Resources\ApprovalDetailResource;
use App\Http\Resources\ApprovalResource;
use App\Http\Resources\InformationUpdateResource;
use App\Models\InformationUpdate;
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

        $updates = $query->paginate($request->per_page ?? 10);

        return ApprovalResource::collection($updates);
    }


    /**
     * @param InformationUpdate $informationUpdate
     *
     * @return JsonResponse
     */
    public function show(InformationUpdate $informationUpdate)
    {
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

            return ApiResponse::success(
                null,
                'Approval successful'
            );

        } catch (\Throwable $e) {
            Log::error('Approval failed', [
                'update_id' => $informationUpdate->uuid,
                'error' => $e
            ]);

            return ApiResponse::error('Unable to approve request');
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

            return ApiResponse::success(
                null,
                'Request rejected'
            );

        } catch (\Throwable $e) {
            Log::error('Rejection failed', [
                'update_id' => $informationUpdate->uuid,
                'error' => $e
            ]);

            return ApiResponse::error('Unable to reject request');
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

}
