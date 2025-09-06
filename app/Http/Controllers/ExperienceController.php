<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExperienceRequest;
use App\Http\Requests\UpdateExperienceRequest;
use App\Http\Resources\ExperienceResource;
use App\Models\Employee;
use App\Models\Experience;
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

class ExperienceController extends Controller
{
    use UsePrint, InformationUpdate;

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $experiences = Experience::query();

        $employee = Employee::query()->where('uuid', $request->employeeId)->first();
        $experiences->where('employee_id', $employee->id);

        return ExperienceResource::collection($experiences->paginate($request->perPage ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreExperienceRequest $request
     * @return ExperienceResource|JsonResponse
     */
    public function store(StoreExperienceRequest $request): JsonResponse|ExperienceResource
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $employee = Employee::query()->where('uuid', $request->employee_id)->first();
            $request['to'] = self::formatDate($request['to']);
            $request['from'] = self::formatDate($request['from']);

            if ($this->isHrAdmin()) {
                $request['user_id'] = $user->id;
                $experience = $employee->experiences()->create($request->all());
            } else {
                $experience = $employee->experiences()->create(['user_id' => $user->id]);

                $this->infoDifference($experience, $request->all());
                $this->requestUpdate($experience);
            }

            DB::commit();

            return new ExperienceResource($experience);
        } catch (Exception $exception) {
            DB::rollBack();
            Log::error('Add Experience Error: ', [$exception]);

            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param Experience $experience
     * @return Response
     */
    public function show(Experience $experience)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param Experience $experience
     * @return Response
     */
    public function edit(Experience $experience)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateExperienceRequest $request
     * @param Experience $experience
     * @return Response
     */
    public function update(UpdateExperienceRequest $request, Experience $experience)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Experience $experience
     * @return Response
     */
    public function destroy(Experience $experience)
    {
        //
    }
}
