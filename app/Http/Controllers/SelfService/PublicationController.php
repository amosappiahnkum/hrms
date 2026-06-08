<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicationRequest;
use App\Http\Requests\UpdatePublicationRequest;
use App\Http\Resources\PublicationResource;
use App\Models\InformationUpdate;
use App\Models\SelfService\Employee;
use App\Models\SelfService\Publication;
use App\Services\UpdateApprovalService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Throwable;

class PublicationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
//     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $publications = Publication::query();

        $publications->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->latest();

        $collection = PublicationResource::collection($publications->paginate($request->per_page ?? 10));

        $pending = [];
        if ($request->employee_uuid) {
            $employee = Employee::where('uuid', $request->employee_uuid)->first();
            if ($employee) {
                $pending = InformationUpdate::where('information_type', 'Publication')
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

    public function store(StorePublicationRequest $request)
    {
        try {
            $validated = $request->validated();

            if ($this->isHrAdmin()) {
                Publication::create($validated);
            } else {
                app(UpdateApprovalService::class)->create(new Publication(), $validated, Auth::id());
            }

            return ApiResponse::success(null, 'Publication creation request submitted for approval');
        } catch (Throwable $e) {
            Log::error('Add Publication Error', ['error' => $e]);
            return ApiResponse::error('Something went wrong');
        }
    }

    public function show(Publication $publication)
    {
        return ApiResponse::success($publication);
    }

    public function update(UpdatePublicationRequest $request, Publication $publication)
    {
        DB::beginTransaction();
        try {
            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $publication->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($publication, $changes, Auth::id());
            }

            DB::commit();
            return new PublicationResource($publication);
        } catch (Exception $exception) {
            Log::error('Update Publication Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }

    public function destroy(Publication $publication)
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $publication->delete();
            } else {
                app(UpdateApprovalService::class)->delete($publication, Auth::id());
            }

            DB::commit();
            return ApiResponse::success(null, 'Delete request submitted for approval', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {
            Log::error('Delete Publication Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }

    public function getMyPublications(Employee $employee)
    {
        $publications = $employee->publications;

        return response()->json($publications);
    }
}
