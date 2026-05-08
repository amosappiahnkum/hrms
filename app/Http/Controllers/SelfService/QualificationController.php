<?php

namespace App\Http\Controllers\SelfService;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQualificationRequest;
use App\Http\Requests\UpdateQualificationRequest;
use App\Http\Resources\QualificationResource;
use App\Models\Education;
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

class QualificationController extends Controller
{
    protected string $docPath = 'docs/qualifications';

    protected array $allowedFiles = ['pdf', 'jpeg', 'png'];

    use UsePrint;

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $educations = Education::query();

        $educations->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('date');

        return QualificationResource::collection($educations->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreQualificationRequest $request
     * @return QualificationResource|JsonResponse
     * @throws Throwable
     */
    public function store(StoreQualificationRequest $request): JsonResponse|QualificationResource
    {

        try {
            $validated = $request->validated();

            if ($this->isHrAdmin()) {
                Education::create($validated);
            } else {

                app(UpdateApprovalService::class)->create(
                    new Education(),
                    $validated,
                    Auth::id()
                );
            }

            return ApiResponse::success(
                null,
                'Education creation request submitted for approval'
            );

        } catch (Throwable $e) {
            Log::error('Add Dependant Error', ['error' => $e]);

            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateQualificationRequest $request
     * @param Education $qualification
     * @return QualificationResource|JsonResponse
     * @throws Throwable
     */
    public function update(UpdateQualificationRequest $request, Education $qualification): JsonResponse|QualificationResource
    {
        DB::beginTransaction();
        try {

            $changes = $request->validated();

            if ($this->isHrAdmin()) {
                $qualification->update($changes);
            } else {
                app(UpdateApprovalService::class)->update($qualification, $changes, Auth::id());
            }

            DB::commit();
            return new QualificationResource($qualification);
        } catch (Exception $exception) {
            Log::error('Update Dependant Error', ['error' => $exception]);
            return response()->json(['message' => 'Something went wrong'], 400);
        }
    }

    public function show(Education $qualification)
    {
        return ApiResponse::success(QualificationResource::make($qualification));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Education $qualification
     * @return JsonResponse|null
     * @throws Throwable
     */
    public function destroy(Education $qualification): ?JsonResponse
    {

        DB::beginTransaction();
        try {

            if ($this->isHrAdmin()) {
                $qualification->delete();
            } else {
                app(UpdateApprovalService::class)->delete($qualification, Auth::id());
            }

            DB::commit();

            return ApiResponse::success(null, 'Delete request submitted for approval', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {

            Log::error('Delete qualification Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
