<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQualificationRequest;
use App\Http\Requests\UpdateQualificationRequest;
use App\Http\Resources\QualificationResource;
use App\Models\ActivityLog;
use App\Models\Education;
use App\Models\Employee;
use App\Traits\InformationUpdate;
use App\Traits\UsePrint;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
     * @return AnonymousResourceCollection|Response|BinaryFileResponse
     */
    public function index(Request $request): Response|BinaryFileResponse|AnonymousResourceCollection
    {
        $educations = Education::query();

        $employee = Employee::query()->where('uuid', $request->employeeId)->first();
        $educations->where('employee_id', $employee->id);

        return QualificationResource::collection($educations->paginate($request->perPage ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreQualificationRequest $request
     * @return QualificationResource|JsonResponse
     */
    public function store(StoreQualificationRequest $request): JsonResponse|QualificationResource
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $employee = Employee::query()->where('uuid', $request->employee_id)->first();

            $request['date'] = Carbon::parse($request->date)->format('Y-m-d');

            if ($this->isHrAdmin()) {
                $request['user_id'] = $user->id;
                $qualification = $employee->qualifications()->create($request->all());
            } else {
                $qualification = $employee->qualifications()->create(['user_id' => $user->id]);

                $request['date'] = Carbon::parse($request->date)->format('Y-m-d');

                $this->infoDifference($qualification, $request->all());
                $this->requestUpdate($qualification);
            }

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' added emergency contact for ' . $employee->name,
                'created', [''], 'qualification')
                ->to($employee)
                ->as($user);

            /*if ($qualification && $request->has('file') && $request->file !== "null") {
                $saveFile = new SaveFile($qualification, $request->file('file'), $this->docPath, $this->allowedFiles);
                $photo = $saveFile->save();

                $this->infoDifference($photo, [
                    'file_name' => $saveFile->fileName
                ]);

                $this->requestUpdate($photo);
            }*/

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
     * @param $id
     * @return QualificationResource|JsonResponse
     */
    public function update(UpdateQualificationRequest $request, $id): JsonResponse|QualificationResource
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();

            $qualification = Education::findOrFail($id);
            $request['date'] = Carbon::parse($request->date)->format('Y-m-d');

            if ($this->isHrAdmin()) {
                $qualification->update($request->all());
                $qualification->save();
            } else {
                $this->infoDifference($qualification, $request->all());
                $this->requestUpdate($qualification);
            }

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' updated the qualification for ' . $qualification->employee->name,
                'updated', [''], 'qualification')
                ->to($qualification->employee)
                ->as($user);
            /*if ($request->has('file') && $request->file !== "null") {
                $saveFile = new SaveFile($qualification, $request->file('file'), $this->docPath, $this->allowedFiles);
                $photo = $saveFile->save($qualification->photo->file_name ?? null);

                $this->infoDifference($photo, [
                    'file_name' => $saveFile->fileName
                ]);

                $this->requestUpdate($photo);
            }*/
            DB::commit();
            return new QualificationResource($qualification);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse|null
     */
    public function destroy($id): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $education = Education::findOrFail($id);
            $education->photo()->delete();
            $education->informationUpdate()->delete();
            $education->delete();
            DB::commit();
            return response()->json([
                'message' => 'Qualification Deleted'
            ]);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }
}
