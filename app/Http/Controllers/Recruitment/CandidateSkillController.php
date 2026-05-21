<?php

namespace App\Http\Controllers\Recruitment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreCandidateSkillRequest;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\CandidateSkill;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CandidateSkillController extends Controller
{
    protected function candidateFromAuth(): Candidate
    {
        return Auth::guard('candidate')->user();
    }

    public function index(Request $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        $skills = $candidate->skills()->latest()->get();

        return ApiResponse::success($skills->map(fn($s) => [
            'uuid'       => $s->uuid,
            'name'       => $s->name,
            'level'      => $s->level,
            'created_at' => $s->created_at,
        ]));
    }

    public function store(StoreCandidateSkillRequest $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        try {
            $skill = $candidate->skills()->create($request->validated());

            return ApiResponse::success([
                'uuid'       => $skill->uuid,
                'name'       => $skill->name,
                'level'      => $skill->level,
                'created_at' => $skill->created_at,
            ], 'Skill added', 201);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function show(CandidateSkill $skill): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($skill->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this skill record.');
        }

        return ApiResponse::success([
            'uuid'       => $skill->uuid,
            'name'       => $skill->name,
            'level'      => $skill->level,
            'created_at' => $skill->created_at,
        ]);
    }

    public function update(StoreCandidateSkillRequest $request, CandidateSkill $skill): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($skill->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this skill record.');
        }

        try {
            $skill->update($request->validated());
            $skill->refresh();

            return ApiResponse::success([
                'uuid'       => $skill->uuid,
                'name'       => $skill->name,
                'level'      => $skill->level,
                'created_at' => $skill->created_at,
            ], 'Skill updated');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function destroy(CandidateSkill $skill): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($skill->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this skill record.');
        }

        try {
            $skill->delete();
            return ApiResponse::success(null, 'Skill deleted');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }
}
