<?php

namespace App\Http\Controllers\Recruitment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreCandidateRequest;
use App\Http\Requests\Recruitment\UpdateCandidateRequest;
use App\Http\Resources\Recruitment\CandidateResource;
use App\Models\Recruitment\Candidate;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class CandidateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Candidate::query()
            ->when($request->search, function ($q) use ($request) {
                $q->where('first_name', 'like', "%{$request->search}%")
                    ->orWhere('last_name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            })
            ->latest();

        return CandidateResource::collection($query->paginate($request->per_page ?? 10));
    }

    public function store(StoreCandidateRequest $request): CandidateResource|JsonResponse
    {
        try {
            $candidate = Candidate::create($request->validated());
            return new CandidateResource($candidate);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function show(Candidate $candidate): JsonResponse
    {
        Log::info('osia', [$candidate]);
        $candidate->loadCount(['experiences', 'qualifications', 'skills', 'documents', 'applications']);
        return ApiResponse::success(new CandidateResource($candidate));
    }

    public function experiences(Candidate $candidate): JsonResponse
    {
        $items = $candidate->experiences()->orderBy('start_date', 'desc')->get()->map(fn($e) => [
            'uuid'         => $e->uuid,
            'company_name' => $e->company_name,
            'job_title'    => $e->job_title,
            'start_date'   => $e->start_date?->format('Y-m-d'),
            'end_date'     => $e->end_date?->format('Y-m-d'),
            'is_current'   => $e->is_current,
            'description'  => $e->description,
            'created_at'   => $e->created_at,
        ]);
        return ApiResponse::success($items);
    }

    public function qualifications(Candidate $candidate): JsonResponse
    {
        $items = $candidate->qualifications()->orderBy('start_date', 'desc')->get()->map(fn($q) => [
            'uuid'           => $q->uuid,
            'institution'    => $q->institution,
            'award'          => $q->award,
            'field_of_study' => $q->field_of_study,
            'start_date'     => $q->start_date?->format('Y-m-d'),
            'end_date'       => $q->end_date?->format('Y-m-d'),
            'grade'          => $q->grade,
            'created_at'     => $q->created_at,
        ]);
        return ApiResponse::success($items);
    }

    public function skills(Candidate $candidate): JsonResponse
    {
        $items = $candidate->skills()->get()->map(fn($s) => [
            'uuid'       => $s->uuid,
            'name'       => $s->name,
            'level'      => $s->level,
            'created_at' => $s->created_at,
        ]);
        return ApiResponse::success($items);
    }

    public function languages(Candidate $candidate): JsonResponse
    {
        $items = $candidate->languages()->get()->map(fn($l) => [
            'uuid'        => $l->uuid,
            'name'        => $l->name,
            'proficiency' => $l->proficiency,
            'created_at'  => $l->created_at,
        ]);
        return ApiResponse::success($items);
    }

    public function documents(Candidate $candidate): JsonResponse
    {
        $items = $candidate->documents()->latest()->get()->map(fn($d) => [
            'uuid'         => $d->uuid,
            'type'         => $d->type,
            'display_name' => $d->display_name,
            'path'         => $d->path,
            'mime_type'    => $d->mime_type,
            'size'         => $d->size,
            'created_at'   => $d->created_at,
        ]);
        return ApiResponse::success($items);
    }

    public function applications(Candidate $candidate): JsonResponse
    {
        $items = $candidate->applications()
            ->with(['jobOpening.department'])
            ->latest('applied_at')
            ->get()
            ->map(fn($a) => [
                'uuid'        => $a->uuid,
                'status'      => $a->status,
                'applied_at'  => $a->applied_at?->format('Y-m-d H:i:s'),
                'job_opening' => $a->jobOpening ? [
                    'uuid'       => $a->jobOpening->uuid,
                    'title'      => $a->jobOpening->title,
                    'department' => $a->jobOpening->department?->name ?? null,
                    'location'   => $a->jobOpening->location,
                ] : null,
            ]);
        return ApiResponse::success($items);
    }

    public function update(UpdateCandidateRequest $request, Candidate $candidate): CandidateResource|JsonResponse
    {
        try {
            $candidate->update($request->validated());
            return new CandidateResource($candidate->refresh());
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy(Candidate $candidate): JsonResponse
    {
        try {
            $candidate->delete();
            return response()->json(['message' => 'Candidate deleted']);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
