<?php

namespace App\Http\Controllers;

use App\Enums\Statuses;
use App\Http\Requests\UpdateInformationUpdateRequest;
use App\Http\Resources\InformationUpdateResource;
use App\Models\InformationUpdate;
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
        if (!$this->can('approve-employee-update')) {
            return response()->json([
                'message' => 'Not enough permissions'
            ], 400);
        }

        $infoUpdates = InformationUpdate::query()->where('status', $request->status)->orderBy('created_at', 'asc')->paginate($request->per_page ?? 10);

        return InformationUpdateResource::collection($infoUpdates);
    }


    /**
     * @param InformationUpdate $informationUpdate
     *
     * @return InformationUpdateResource
     */
    public function show(InformationUpdate $informationUpdate): InformationUpdateResource
    {
        return new InformationUpdateResource($informationUpdate);
    }

    /**
     * @param UpdateInformationUpdateRequest $request
     * @param InformationUpdate $informationUpdate
     *
     * @return JsonResponse
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
}
