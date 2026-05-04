<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreQualificationRequest;
use App\Http\Requests\UpdateQualificationRequest;
use App\Http\Resources\QualificationResource;
use App\Models\Education;
use App\Traits\InformationUpdate;
use App\Traits\UsePrint;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class QualificationController extends Controller
{
    protected string $docPath = 'docs/qualifications';

    protected array $allowedFiles = ['pdf', 'jpeg', 'png'];

    use UsePrint, InformationUpdate;

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
     * @throws \Throwable
     */
    public function store(StoreQualificationRequest $request): JsonResponse|QualificationResource
    {
        DB::beginTransaction();
        try {
             if ($this->isHrAdmin()) {
                $qualification = Education::create($request->validated());
            } else {
                $qualification = Education::create();
                $this->infoDifference($qualification, $request->validated());
                $this->requestUpdate($qualification);
            }
            /* ActivityLog::add(($user?->employee?->name ?? $user->username) . ' added emergency contact for ' . $employee->name,
                 'created', [''], 'qualification')
                 ->to($employee)
                 ->as($user);*/


            DB::commit();
            return new QualificationResource($qualification);
        } catch (Exception $exception) {
            Log::error('Add Qualification Error: ', [$exception]);

            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateQualificationRequest $request
     * @param Education $qualification
     * @return QualificationResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateQualificationRequest $request, Education $qualification): JsonResponse|QualificationResource
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $qualification->update($request->all());
                $qualification->save();
            } else {
                $this->infoDifference($qualification, $request->all());
                $this->requestUpdate($qualification);
            }


            DB::commit();
            return new QualificationResource($qualification);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
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
     * @throws \Throwable
     */
    public function destroy(Education $qualification): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $qualification->informationUpdate()->delete();
            $qualification->delete();

            DB::commit();

            return ApiResponse::success(null, 'Qualification deleted.', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {

            Log::error('Delete Qualification Error: ', [$exception]);
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
