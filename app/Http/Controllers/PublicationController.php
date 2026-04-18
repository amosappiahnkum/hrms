<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StorePublicationRequest;
use App\Http\Requests\UpdatePublicationRequest;
use App\Http\Resources\PublicationResource;
use App\Models\Employee;
use App\Models\Publication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

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

        return PublicationResource::collection($publications->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StorePublicationRequest $request
     * @return JsonResponse
     */
    public function store(StorePublicationRequest $request)
    {
        $publication = Publication::create([
            ...$request->validated(),
            'user_id' => auth()->id()
        ]);

        return ApiResponse::success($publication);
    }

    /**
     * Display the specified resource.
     *
     * @param Publication $publication
     * @return JsonResponse
     */
    public function show(Publication $publication)
    {
        return  ApiResponse::success($publication);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdatePublicationRequest $request
     * @param Publication $publication
     * @return Response
     */
    public function update(UpdatePublicationRequest $request, Publication $publication)
    {
        $this->authorize('update', $publication);

        $publication->update($request->validated());

        return PublicationResource::make($publication);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Publication $publication
     * @return JsonResponse
     */
    public function destroy(Publication $publication)
    {
        $this->authorize('delete', $publication);

        $publication->delete();

        return ApiResponse::success(null, 'Publication deleted successfully.', ResponseAlias::HTTP_OK);
    }

    public function getMyPublications(Employee $employee)
    {
        $publications = $employee->publications;

        return response()->json($publications);
    }
}
