<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExperienceRequest;
use App\Http\Requests\UpdateExperienceRequest;
use App\Http\Resources\ExperienceResource;
use App\Models\InformationUpdate;
use App\Models\SelfService\Employee;
use App\Models\SelfService\Experience;
use App\Services\UpdateApprovalService;
use App\Traits\UsePrint;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Throwable;

class ExperienceController extends Controller
{
    use UsePrint;

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $experiences = Experience::query();

        $experiences->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('from');

        $collection = ExperienceResource::collection($experiences->paginate($request->per_page ?? 10));

        $pending = [];
        if ($request->employee_uuid) {
            $employee = Employee::where('uuid', $request->employee_uuid)->first();
            if ($employee) {
                $pending = InformationUpdate::where('information_type', 'Experience')
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
     * @param StoreExperienceRequest $request
     * @return ExperienceResource|JsonResponse
     * @throws \Throwable
     */
    public function store(StoreExperienceRequest $request): JsonResponse|ExperienceResource
    {
        try {
            $validated = $request->validated();

            if ($this->isHrAdmin()) {
                Experience::create($validated);
            } else {

                app(UpdateApprovalService::class)->create(
                    new Experience(),
                    $validated,
                    Auth::id()
                );
            }

            return ApiResponse::success(
                null,
                'Experience creation request submitted for approval'
            );

        } catch (Throwable $e) {
            Log::error('Add Experience Error', ['error' => $e]);

            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param Experience $experience
     * @return JsonResponse
     */
    public function show(Experience $experience)
    {
        return ApiResponse::success(ExperienceResource::make($experience));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateExperienceRequest $request
     * @param Experience $experience
     * @return ExperienceResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateExperienceRequest $request, Experience $experience)
    {
        DB::beginTransaction();
        try {

            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $experience->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($experience, $changes, Auth::id());
            }

            DB::commit();
            return new ExperienceResource($experience);
        } catch (Exception $exception) {
            Log::error('Update $experience Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Experience $experience
     * @return JsonResponse
     * @throws \Throwable
     */
    public function destroy(Experience $experience)
    {

        DB::beginTransaction();
        try {

            if ($this->isHrAdmin()) {
                $experience->delete();
            } else {
                app(UpdateApprovalService::class)->delete($experience, Auth::id());
            }

            DB::commit();

            return ApiResponse::success(null, 'Delete request submitted for approval', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {

            Log::error('Delete $experience Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
