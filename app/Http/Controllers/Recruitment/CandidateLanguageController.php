<?php

namespace App\Http\Controllers\Recruitment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreCandidateLanguageRequest;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\CandidateLanguage;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CandidateLanguageController extends Controller
{
    protected function candidateFromAuth(): Candidate
    {
        return Auth::guard('candidate')->user();
    }

    public function index(Request $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        $languages = $candidate->languages()->latest()->get();

        return ApiResponse::success($languages->map(fn($l) => [
            'uuid'        => $l->uuid,
            'name'        => $l->name,
            'proficiency' => $l->proficiency,
            'created_at'  => $l->created_at,
        ]));
    }

    public function store(StoreCandidateLanguageRequest $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        try {
            $language = $candidate->languages()->create($request->validated());

            return ApiResponse::success([
                'uuid'        => $language->uuid,
                'name'        => $language->name,
                'proficiency' => $language->proficiency,
                'created_at'  => $language->created_at,
            ], 'Language added', 201);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function show(CandidateLanguage $language): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($language->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this language record.');
        }

        return ApiResponse::success([
            'uuid'        => $language->uuid,
            'name'        => $language->name,
            'proficiency' => $language->proficiency,
            'created_at'  => $language->created_at,
        ]);
    }

    public function update(StoreCandidateLanguageRequest $request, CandidateLanguage $language): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($language->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this language record.');
        }

        try {
            $language->update($request->validated());
            $language->refresh();

            return ApiResponse::success([
                'uuid'        => $language->uuid,
                'name'        => $language->name,
                'proficiency' => $language->proficiency,
                'created_at'  => $language->created_at,
            ], 'Language updated');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function destroy(CandidateLanguage $language): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($language->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this language record.');
        }

        try {
            $language->delete();
            return ApiResponse::success(null, 'Language deleted');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }
}
