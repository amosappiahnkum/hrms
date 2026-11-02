<?php

namespace App\Http\Controllers\Competency;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\Competency;
use App\Models\Competency\CompetencyAssessment;
use App\Models\Competency\CompetencyRating;
use App\Models\SelfService\Employee;
use App\Services\Competency\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Rating an employee against their position's competencies: a draft, then completed (read-only). */
class CompetencyAssessmentController extends Controller
{
    public function __construct(private readonly CompetencyService $service)
    {
    }

    /** Open the employee's draft, or start one prefilled with their position's requirements and last levels. */
    public function start(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->service->canAssess($request->user(), $employee), 403, 'You cannot assess this employee.');

        $draft = CompetencyAssessment::where('employee_id', $employee->id)->where('status', CompetencyAssessment::DRAFT)->latest('id')->first();

        if (!$draft) {
            $employee->loadMissing('jobDetail');
            $positionId = $employee->jobDetail?->position_id;
            $required = $this->service->requirementsFor($positionId);
            abort_if($required->isEmpty(), 422, 'Set the competencies required for this employee\'s position first.');

            $previous = $this->service->latestAssessments([$employee->id])->get($employee->id)?->ratings->keyBy('competency_id') ?? collect();

            $draft = DB::transaction(function () use ($employee, $positionId, $required, $previous, $request) {
                $assessment = CompetencyAssessment::create([
                    'employee_id' => $employee->id,
                    'position_id' => $positionId,
                    'assessor_id' => $request->user()->id,
                    'status'      => CompetencyAssessment::DRAFT,
                    'assessed_on' => now()->toDateString(),
                ]);

                foreach ($required as $competencyId => $level) {
                    $rating = $assessment->ratings()->create([
                        'competency_id'  => $competencyId,
                        'required_level' => $level,
                        // Start from where they were last time, evidence files included.
                        'level'          => $previous->get($competencyId)?->level,
                        'evidence'       => $previous->get($competencyId)?->evidence,
                    ]);
                    $previous->get($competencyId)?->evidenceFiles->each->copyTo($rating);
                }

                return $assessment;
            });
        }

        return ApiResponse::success($this->detail($draft, $request), 'Assessment ready.');
    }

    public function show(Request $request, CompetencyAssessment $competencyAssessment): JsonResponse
    {
        abort_unless($this->service->canSee($request->user(), $competencyAssessment->employee), 403);

        return ApiResponse::success($this->detail($competencyAssessment, $request));
    }

    public function update(Request $request, CompetencyAssessment $competencyAssessment): JsonResponse
    {
        $this->ensureEditable($request, $competencyAssessment);

        $data = $request->validate([
            'assessed_on'               => ['sometimes', 'date', 'before_or_equal:today'],
            'next_review_on'            => ['nullable', 'date', 'after:assessed_on'],
            'comment'                   => ['nullable', 'string', 'max:5000'],
            'ratings'                   => ['sometimes', 'array'],
            'ratings.*.competency_uuid' => ['required', 'distinct', Rule::exists('competencies', 'uuid')],
            'ratings.*.level'           => ['nullable', 'integer', 'between:0,4'],
            'ratings.*.evidence'        => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($competencyAssessment, $data) {
            $competencyAssessment->update(collect($data)->only(['assessed_on', 'next_review_on', 'comment'])->all());

            $ids = Competency::withTrashed()->whereIn('uuid', collect($data['ratings'] ?? [])->pluck('competency_uuid'))->pluck('id', 'uuid');
            foreach ($data['ratings'] ?? [] as $row) {
                $rating = CompetencyRating::firstOrNew([
                    'competency_assessment_id' => $competencyAssessment->id,
                    'competency_id'            => $ids[$row['competency_uuid']],
                ]);
                $rating->fill(['level' => $row['level'] ?? null, 'evidence' => $row['evidence'] ?? null])->save();
            }
        });

        return ApiResponse::success($this->detail($competencyAssessment->fresh(), $request), 'Assessment saved.');
    }

    /** Lock it in: it becomes the employee's current competency record. */
    public function complete(Request $request, CompetencyAssessment $competencyAssessment): JsonResponse
    {
        $this->ensureEditable($request, $competencyAssessment);

        $unrated = $competencyAssessment->ratings()->whereNotNull('required_level')->whereNull('level')->count();
        if ($unrated && !$request->boolean('allow_unrated')) {
            throw new UserFacingException("{$unrated} required competenc" . ($unrated === 1 ? 'y has' : 'ies have') . ' no level yet. Rate them, or confirm they don\'t apply.');
        }

        $assessedOn = Carbon::parse($competencyAssessment->assessed_on ?? now());
        $competencyAssessment->update([
            'status'         => CompetencyAssessment::COMPLETED,
            'completed_at'   => now(),
            'assessor_id'    => $request->user()->id,
            'assessed_on'    => $assessedOn->toDateString(),
            'next_review_on' => $competencyAssessment->next_review_on ?? $this->service->defaultNextReview($assessedOn)->toDateString(),
        ]);
        // The new levels may no longer support authorizations the employee holds.
        app(\App\Services\Competency\AuthorizationService::class)->recheck($competencyAssessment->employee);

        return ApiResponse::success($this->detail($competencyAssessment->fresh(), $request), 'Assessment completed.');
    }

    public function destroy(Request $request, CompetencyAssessment $competencyAssessment): JsonResponse
    {
        $this->ensureEditable($request, $competencyAssessment);
        $competencyAssessment->ratings()->delete();
        $competencyAssessment->delete();

        return ApiResponse::success(null, 'Draft discarded.');
    }

    private function ensureEditable(Request $request, CompetencyAssessment $assessment): void
    {
        abort_unless($this->service->canAssess($request->user(), $assessment->employee), 403, 'You cannot assess this employee.');

        if (!$assessment->isDraft()) {
            throw new UserFacingException('A completed assessment can\'t be changed. Start a new assessment instead.');
        }
    }

    private function detail(CompetencyAssessment $a, Request $request): array
    {
        $a->load(['ratings.competency' => fn ($q) => $q->withTrashed(), 'ratings.evidenceFiles.uploader', 'employee.department']);

        return $this->service->assessmentSummary($a) + [
            'employee' => ['uuid' => $a->employee->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $a->employee->name)), 'department' => $a->employee->department?->name],
            'ratings'  => $a->ratings
                ->sortBy([fn ($r) => $r->competency->group->value, fn ($r) => $r->competency->name])
                ->map(fn (CompetencyRating $r) => [
                    'uuid'           => $r->uuid,
                    'files'          => $r->evidenceFiles->map->payload()->values(),
                    'competency'     => ['uuid' => $r->competency->uuid, 'name' => $r->competency->name, 'group' => CompetencyService::option($r->competency->group), 'description' => $r->competency->description],
                    'required_level' => $r->required_level,
                    'level'          => $r->level,
                    'gap'            => $r->gap(),
                    'evidence'       => $r->evidence,
                ])->values(),
            'can_edit' => $a->isDraft() && $this->service->canAssess($request->user(), $a->employee),
        ];
    }
}
