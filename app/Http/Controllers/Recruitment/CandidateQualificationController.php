<?php

namespace App\Http\Controllers\Recruitment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreCandidateQualificationRequest;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\CandidateQualification;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CandidateQualificationController extends Controller
{
    protected function candidateFromAuth(): Candidate
    {
        return Auth::guard('candidate')->user();
    }

    public function index(Request $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        $qualifications = $candidate->qualifications()->latest()->get();

        return ApiResponse::success($qualifications->map(fn($q) => [
            'uuid'           => $q->uuid,
            'institution'    => $q->institution,
            'award'          => $q->award,
            'field_of_study' => $q->field_of_study,
            'start_date'     => $q->start_date?->format('Y-m-d'),
            'end_date'       => $q->end_date?->format('Y-m-d'),
            'grade'          => $q->grade,
            'created_at'     => $q->created_at,
        ]));
    }

    public function store(StoreCandidateQualificationRequest $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        try {
            $qualification = $candidate->qualifications()->create($request->validated());

            return ApiResponse::success([
                'uuid'           => $qualification->uuid,
                'institution'    => $qualification->institution,
                'award'          => $qualification->award,
                'field_of_study' => $qualification->field_of_study,
                'start_date'     => $qualification->start_date?->format('Y-m-d'),
                'end_date'       => $qualification->end_date?->format('Y-m-d'),
                'grade'          => $qualification->grade,
                'created_at'     => $qualification->created_at,
            ], 'Qualification added', 201);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function show(CandidateQualification $qualification): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($qualification->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this qualification record.');
        }

        return ApiResponse::success([
            'uuid'           => $qualification->uuid,
            'institution'    => $qualification->institution,
            'award'          => $qualification->award,
            'field_of_study' => $qualification->field_of_study,
            'start_date'     => $qualification->start_date?->format('Y-m-d'),
            'end_date'       => $qualification->end_date?->format('Y-m-d'),
            'grade'          => $qualification->grade,
            'created_at'     => $qualification->created_at,
        ]);
    }

    public function update(StoreCandidateQualificationRequest $request, CandidateQualification $qualification): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($qualification->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this qualification record.');
        }

        try {
            $qualification->update($request->validated());
            $qualification->refresh();

            return ApiResponse::success([
                'uuid'           => $qualification->uuid,
                'institution'    => $qualification->institution,
                'award'          => $qualification->award,
                'field_of_study' => $qualification->field_of_study,
                'start_date'     => $qualification->start_date?->format('Y-m-d'),
                'end_date'       => $qualification->end_date?->format('Y-m-d'),
                'grade'          => $qualification->grade,
                'created_at'     => $qualification->created_at,
            ], 'Qualification updated');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function destroy(CandidateQualification $qualification): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($qualification->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this qualification record.');
        }

        try {
            $qualification->delete();
            return ApiResponse::success(null, 'Qualification deleted');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }
}
