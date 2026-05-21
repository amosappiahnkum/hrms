<?php

namespace App\Http\Controllers\Recruitment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreCandidateExperienceRequest;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\CandidateExperience;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CandidateExperienceController extends Controller
{
    protected function candidateFromAuth(): Candidate
    {
        return Auth::guard('candidate')->user();
    }

    public function index(Request $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        $experiences = $candidate->experiences()->latest()->get();

        return ApiResponse::success($experiences->map(fn($e) => [
            'uuid'         => $e->uuid,
            'company_name' => $e->company_name,
            'job_title'    => $e->job_title,
            'start_date'   => $e->start_date?->format('Y-m-d'),
            'end_date'     => $e->end_date?->format('Y-m-d'),
            'is_current'   => $e->is_current,
            'description'  => $e->description,
            'created_at'   => $e->created_at,
        ]));
    }

    public function store(StoreCandidateExperienceRequest $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        try {
            $experience = $candidate->experiences()->create($request->validated());

            return ApiResponse::success([
                'uuid'         => $experience->uuid,
                'company_name' => $experience->company_name,
                'job_title'    => $experience->job_title,
                'start_date'   => $experience->start_date?->format('Y-m-d'),
                'end_date'     => $experience->end_date?->format('Y-m-d'),
                'is_current'   => $experience->is_current,
                'description'  => $experience->description,
                'created_at'   => $experience->created_at,
            ], 'Experience added', 201);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function show(CandidateExperience $experience): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($experience->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this experience record.');
        }

        return ApiResponse::success([
            'uuid'         => $experience->uuid,
            'company_name' => $experience->company_name,
            'job_title'    => $experience->job_title,
            'start_date'   => $experience->start_date?->format('Y-m-d'),
            'end_date'     => $experience->end_date?->format('Y-m-d'),
            'is_current'   => $experience->is_current,
            'description'  => $experience->description,
            'created_at'   => $experience->created_at,
        ]);
    }

    public function update(StoreCandidateExperienceRequest $request, CandidateExperience $experience): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($experience->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this experience record.');
        }

        try {
            $experience->update($request->validated());
            $experience->refresh();

            return ApiResponse::success([
                'uuid'         => $experience->uuid,
                'company_name' => $experience->company_name,
                'job_title'    => $experience->job_title,
                'start_date'   => $experience->start_date?->format('Y-m-d'),
                'end_date'     => $experience->end_date?->format('Y-m-d'),
                'is_current'   => $experience->is_current,
                'description'  => $experience->description,
                'created_at'   => $experience->created_at,
            ], 'Experience updated');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function destroy(CandidateExperience $experience): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($experience->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this experience record.');
        }

        try {
            $experience->delete();
            return ApiResponse::success(null, 'Experience deleted');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }
}
