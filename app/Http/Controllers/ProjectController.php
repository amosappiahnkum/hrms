<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $projects = Project::query();

        $projects->when($request->employee_uuid, function ($query, $employee_uuid) {
            $query->whereHas('employee', function ($q) use ($employee_uuid) {
                $q->where('uuid', $employee_uuid);
            });
        })->orderByDesc('year');

        return ProjectResource::collection($projects->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreProjectRequest $request
     * @return ProjectResource|JsonResponse
     * @throws \Throwable
     */
    public function store(StoreProjectRequest $request): ProjectResource|JsonResponse
    {
        try {
            $project = Project::create($request->validated());

            return ApiResponse::success(ProjectResource::make($project));
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param UpdateProjectRequest $request
     * @param Project $project
     * @return ProjectResource|JsonResponse
     * @throws \Throwable
     */
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse|ProjectResource
    {
        try {
            $project->update($request->validated());

            return ApiResponse::success(ProjectResource::make($project));
        }catch (Exception $exception){
            Log::error($exception->getMessage());

            return ApiResponse::error('Something went wrong');
        }
    }

    public function show(Project $project)
    {
        return ApiResponse::success(ProjectResource::make($project));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Project $project
     * @return JsonResponse|null
     * @throws \Throwable
     */
    public function destroy(Project $project): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $project->delete();
            DB::commit();
            return ApiResponse::success(null, 'Project deleted.', ResponseAlias::HTTP_OK);
        }catch (Exception $exception){

            Log::error($exception->getMessage());
            return ApiResponse::error('Something went wrong', [], ResponseAlias::HTTP_OK);
        }
    }
}
