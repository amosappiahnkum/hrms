<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreExperienceRequest;
use App\Http\Requests\UpdateExperienceRequest;
use App\Http\Resources\ExperienceResource;
use App\Models\Experience;
use App\Traits\InformationUpdate;
use App\Traits\UsePrint;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

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


        $experiences->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('from');

        return ExperienceResource::collection($experiences->paginate($request->per_page ?? 10));
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
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $request['to'] = self::formatDate($request['to']);
            $request['from'] = self::formatDate($request['from']);

            if ($this->isHrAdmin()) {
                $request['user_id'] = $user->id;
                $experience = Experience::create($request->all());
            } else {
                $experience = Experience::create(['user_id' => $user->id]);

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
     * @return JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateExperienceRequest $request, Experience $experience)
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $experience->update($request->all());
                $experience->save();
            } else {
                $this->infoDifference($experience, $request->all());
                $this->requestUpdate($experience);
            }

            DB::commit();
            return ApiResponse::success(ExperienceResource::make($experience));
        } catch (Exception $exception) {
            DB::rollBack();
            Log::error('Update Experience Error: ', [$exception]);
            return ApiResponse::error('Something went wrong');
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
            $experience->informationUpdate()->delete();
            $experience->delete();

            DB::commit();

            return ApiResponse::success(null, 'Qualification deleted.', ResponseAlias::HTTP_OK);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }
}
