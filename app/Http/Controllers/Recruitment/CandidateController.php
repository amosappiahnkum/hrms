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
        $candidate->load([
            'applications.jobOpening.department',
            'experiences',
            'qualifications',
            'skills',
            'languages',
            'documents',
        ]);
        return ApiResponse::success(new CandidateResource($candidate));
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
