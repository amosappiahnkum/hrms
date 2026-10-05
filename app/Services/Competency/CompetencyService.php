<?php

namespace App\Services\Competency;

use App\Enums\Competency\CompetencyLevel;
use App\Models\Competency\Competency;
use App\Models\Competency\CompetencyAssessment;
use App\Models\Competency\CompetencyRating;
use App\Models\Competency\DevelopmentAction;
use App\Models\Competency\PositionCompetency;
use App\Models\Config\Department;
use App\Models\Config\Setting;
use App\Models\EmployeeSupervisor;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The competency matrix: what each position requires, where each employee stands (their latest
 * completed assessment), and who may see or assess whom.
 */
class CompetencyService
{
    /** Required levels for a position, keyed by competency id. */
    public function requirementsFor(?int $positionId): Collection
    {
        if (!$positionId) {
            return collect();
        }

        return PositionCompetency::where('position_id', $positionId)
            ->whereHas('competency')
            ->pluck('required_level', 'competency_id');
    }

    /** Each employee's latest completed assessment, keyed by employee id. */
    public function latestAssessments(Collection|array $employeeIds): Collection
    {
        return CompetencyAssessment::completed()
            ->whereIn('employee_id', $employeeIds)
            ->with('ratings')
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');
    }

    /** Ratings from each employee's latest completed assessment only (no newer completed one exists). */
    public function latestRatings(): Builder
    {
        return CompetencyRating::query()->whereHas('assessment', fn ($a) => $a->completed()->whereNotExists(fn ($q) => $q
            ->from('competency_assessments as newer')
            ->whereColumn('newer.employee_id', 'competency_assessments.employee_id')
            ->where('newer.status', CompetencyAssessment::COMPLETED)
            ->whereNull('newer.deleted_at')
            ->where(fn ($w) => $w->whereColumn('newer.completed_at', '>', 'competency_assessments.completed_at')
                ->orWhere(fn ($x) => $x->whereColumn('newer.completed_at', 'competency_assessments.completed_at')
                    ->whereColumn('newer.id', '>', 'competency_assessments.id')))));
    }

    public function reviewIntervalMonths(): int
    {
        return (int) (Setting::where('key', 'competency.review_interval_months')->value('value') ?? 12) ?: 12;
    }

    public function defaultNextReview(Carbon $assessedOn): Carbon
    {
        return $assessedOn->copy()->addMonthsNoOverflow($this->reviewIntervalMonths());
    }

    // ── Access ──────────────────────────────────────────────────────────────────
    //
    // Two ways in:
    //  - a competency permission (HR, IMS team…): the whole organisation, from Employee Management;
    //  - leading people (heading a department, or having direct reports): their team, from self-service.

    /** Holds a competency permission: sees, and (with assess/manage) assesses, everyone. */
    public function seesEveryone(User $user): bool
    {
        return $user->canAny(['view-competencies', 'assess-competencies', 'manage-competencies']);
    }

    /** Heads a department or supervises someone. */
    public function leadsPeople(User $user): bool
    {
        return $user->employee_id && (
            Department::where('hod', $user->employee_id)->exists()
            || EmployeeSupervisor::where('supervisor_id', $user->employee_id)->exists()
        );
    }

    public function hasAccess(User $user): bool
    {
        return $this->seesEveryone($user) || $this->leadsPeople($user);
    }

    /** Limit an employee query to who the user may see: everyone, or their team. */
    public function scopeVisible(Builder $employees, User $user): Builder
    {
        return $this->seesEveryone($user) ? $employees : $this->scopeTeam($employees, $user);
    }

    /** The departments the user heads (with their sub-departments) and their direct reports. */
    public function scopeTeam(Builder $employees, User $user): Builder
    {
        if (!$user->employee_id) {
            return $employees->whereRaw('1 = 0');
        }

        $departmentIds = Department::where('hod', $user->employee_id)->get()
            ->flatMap(fn (Department $d) => $this->withSubDepartments($d))->unique();
        $reportIds = EmployeeSupervisor::where('supervisor_id', $user->employee_id)->pluck('employee_id');

        return $employees->where(fn ($q) => $q->whereIn('department_id', $departmentIds)->orWhereIn('id', $reportIds))
            ->where('id', '!=', $user->employee_id);
    }

    public function canSee(User $user, Employee $employee): bool
    {
        if ($user->employee_id && $user->employee_id === $employee->id) {
            return true;
        }

        return $this->scopeVisible(Employee::query()->whereKey($employee->id), $user)->exists();
    }

    public function canAssess(User $user, Employee $employee): bool
    {
        // Nobody rates themselves.
        if ($user->employee_id && $user->employee_id === $employee->id) {
            return false;
        }

        if ($user->canAny(['assess-competencies', 'manage-competencies'])) {
            return true;
        }

        // Leaders assess their own team.
        return $this->scopeTeam(Employee::query()->whereKey($employee->id), $user)->exists();
    }

    /**
     * Employees the user may see, narrowed by the matrix filters: a department (with its
     * sub-departments), a position and a name/staff ID search.
     */
    public function employees(User $user, array $filters): Builder
    {
        $departmentIds = null;
        if (!empty($filters['department_uuid'])) {
            $root = Department::where('uuid', $filters['department_uuid'])->first();
            $departmentIds = $root ? $this->withSubDepartments($root) : collect([0]);
        }

        return $this->scopeVisible(Employee::query(), $user)
            ->when($departmentIds, fn ($q) => $q->whereIn('department_id', $departmentIds))
            ->when($filters['position_uuid'] ?? null, fn ($q, $v) => $q->whereHas('jobDetail.position', fn ($p) => $p->where('uuid', $v)))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->search($v));
    }

    /** A department's id and all its sub-departments' ids. */
    public function withSubDepartments(Department $department): Collection
    {
        $ids = collect([$department->id]);
        $frontier = [$department->id];

        while ($frontier) {
            $frontier = Department::whereIn('parent_department_id', $frontier)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = $ids->merge($frontier);
        }

        return $ids;
    }

    // ── Profile ───────────────────────────────────────────────────────────────

    /**
     * Where an employee stands: each competency their position requires (plus any rated), with
     * the required and current level, the gap and the actions planned for it.
     */
    public function profile(Employee $employee, ?User $viewer = null): array
    {
        $employee->loadMissing(['jobDetail.position', 'department']);
        $position = $employee->jobDetail?->position;
        $required = $this->requirementsFor($position?->id);

        $latest = $this->latestAssessments([$employee->id])->get($employee->id);
        $latest?->ratings->load('evidenceFiles.uploader');
        $ratings = $latest?->ratings->keyBy('competency_id') ?? collect();

        $actions = DevelopmentAction::where('employee_id', $employee->id)
            ->with(['competency', 'trainingPlanItem.plan'])
            ->latest('id')->get();

        $competencyIds = $required->keys()->merge($ratings->keys())->unique();
        $competencies = Competency::withTrashed()->whereIn('id', $competencyIds)->get()->keyBy('id');

        $rows = $competencyIds->map(function ($id) use ($required, $ratings, $competencies, $actions) {
            $competency = $competencies->get($id);
            $rating = $ratings->get($id);
            $requiredLevel = $required->get($id);
            $level = $rating?->level;

            return [
                'competency'     => ['uuid' => $competency->uuid, 'name' => $competency->name, 'group' => self::option($competency->group)],
                'required_level' => $requiredLevel,
                'level'          => $level,
                'gap'            => $requiredLevel !== null && $level !== null ? max(0, $requiredLevel - $level) : 0,
                'assessed'       => $rating !== null,
                'evidence'       => $rating?->evidence,
                'files'          => $rating?->evidenceFiles->map->payload()->values() ?? [],
                'open_actions'   => $actions->where('competency_id', $id)->filter(fn ($a) => $a->status->isOpen())->count(),
            ];
        })->sortBy([fn ($r) => $r['competency']['group']['value'] ?? '', fn ($r) => $r['competency']['name']])->values();

        $certificates = app(CertificationStatusService::class);
        $certifications = $certificates->forEmployee($employee);

        $draft = CompetencyAssessment::where('employee_id', $employee->id)->where('status', CompetencyAssessment::DRAFT)->latest('id')->first();
        $history = CompetencyAssessment::completed()->where('employee_id', $employee->id)
            ->with(['assessor', 'position'])->withCount('ratings')
            ->orderByDesc('completed_at')->limit(20)->get();

        $summary = [
            'required'   => $required->count(),
            'met'        => $rows->filter(fn ($r) => $r['required_level'] !== null && $r['assessed'] && $r['gap'] === 0)->count(),
            'gaps'       => $rows->where('gap', '>', 0)->count(),
            'not_rated'  => $rows->filter(fn ($r) => $r['required_level'] !== null && !$r['assessed'])->count(),
            'certification_gaps' => $certificates->gaps($certifications)->count(),
        ];
        $authorizations = \App\Models\Competency\EmployeeAuthorization::current()->where('employee_id', $employee->id)
            ->whereHas('activity')->with('activity')->orderBy('status')->get();

        return [
            // The one-line answer: is this person competent and authorized, and is something being done about the gaps?
            'readiness' => [
                'meets_requirements' => $summary['required'] > 0 && !$summary['gaps'] && !$summary['not_rated'] && !$summary['certification_gaps'],
                'open_actions'       => $actions->filter(fn ($a) => $a->status->isOpen())->count(),
                'gaps_without_plan'  => $rows->filter(fn ($r) => $r['gap'] > 0 && !$r['open_actions'])->count(),
                'authorized'         => $authorizations->where('status', \App\Enums\Competency\AuthorizationStatus::AUTHORIZED)->count(),
            ],
            'employee' => [
                'uuid'       => $employee->uuid,
                'name'       => trim(preg_replace('/\s+/', ' ', $employee->name)),
                'staff_id'   => $employee->staff_id,
                'department' => $employee->department?->name,
            ],
            'position'     => $position ? ['uuid' => $position->uuid, 'name' => $position->name] : null,
            'competencies' => $rows,
            // Certificates the position requires, and where the employee stands on each.
            'certifications' => $certifications,
            // What the employee is authorized (or recommended) to do.
            'authorizations' => $authorizations->map(fn ($a) => [
                    'uuid'        => $a->uuid,
                    'activity'    => ['uuid' => $a->activity->uuid, 'name' => $a->activity->name],
                    'status'      => self::option($a->status),
                    'valid_until' => $a->valid_until?->toDateString(),
                    'reason'      => $a->reason,
                ])->values(),
            'summary'      => $summary,
            'latest_assessment' => $latest ? $this->assessmentSummary($latest) : null,
            'draft'             => $draft ? ['uuid' => $draft->uuid, 'updated_at' => $draft->updated_at] : null,
            'review_due'        => $latest?->next_review_on && $latest->next_review_on->isPast(),
            'actions'           => $actions->map(fn ($a) => $this->action($a))->values(),
            'trainings'         => $this->trainings($employee, $actions),
            'history'           => $history->map(fn ($a) => $this->assessmentSummary($a))->values(),
            'can_assess'        => $viewer ? $this->canAssess($viewer, $employee) : false,
        ];
    }

    /**
     * The employee's approved trainings, newest plan first, with what happened and how it was
     * evaluated, and the competencies each was meant to develop.
     */
    private function trainings(Employee $employee, Collection $actions): array
    {
        if (!feature('training_plan.enabled')) {
            return [];
        }

        $developed = $actions->whereNotNull('training_plan_item_id')->groupBy('training_plan_item_id')
            ->map(fn ($group) => $group->map(fn ($a) => $a->competency?->name)->filter()->unique()->values());

        return \App\Models\TrainingPlan\TrainingPlanItem::approved()->where('employee_id', $employee->id)
            ->whereHas('plan')->with(['plan', 'evaluations'])
            ->get()
            ->sortBy([fn ($a, $b) => $b->plan->year <=> $a->plan->year, fn ($a, $b) => strcmp($a->quarter, $b->quarter)])
            ->take(30)
            ->map(function ($item) use ($developed) {
                $feedback = $item->evaluations->first(fn ($e) => $e->type === \App\Enums\TrainingPlan\EvaluationType::PARTICIPANT_FEEDBACK);
                $review = $item->evaluations->first(fn ($e) => $e->type === \App\Enums\TrainingPlan\EvaluationType::SUPERVISOR_REVIEW);

                return [
                    'uuid'         => $item->uuid,
                    'title'        => $item->title,
                    'year'         => $item->plan->year,
                    'quarter'      => $item->quarter,
                    'status'       => self::option($item->status),
                    'completed_at' => $item->completed_at?->toDateString(),
                    'hours'        => $item->hours,
                    'attended'     => $item->attended,
                    'score'        => $item->score,
                    'passed'       => $item->passed,
                    'competencies' => $developed->get($item->id, collect())->all(),
                    'feedback'     => $feedback ? ['status' => $feedback->status(), 'rating' => $feedback->rating] : null,
                    'review'       => $review ? ['status' => $review->status(), 'applied_on_job' => $review->applied_on_job, 'rating' => $review->rating, 'due_on' => $review->due_on->toDateString()] : null,
                ];
            })->values()->all();
    }

    public function assessmentSummary(CompetencyAssessment $a): array
    {
        $a->loadMissing(['assessor', 'position']);

        return [
            'uuid'           => $a->uuid,
            'status'         => $a->status,
            'assessed_on'    => $a->assessed_on?->toDateString(),
            'next_review_on' => $a->next_review_on?->toDateString(),
            'assessor'       => $a->assessor?->name,
            'position'       => $a->position?->name,
            'comment'        => $a->comment,
        ];
    }

    public function action(DevelopmentAction $a): array
    {
        $a->loadMissing(['competency', 'trainingPlanItem.plan', 'trainingPlanItem.evaluations', 'evaluator', 'evidenceFiles.uploader']);
        $item = $a->trainingPlanItem;
        $review = $item?->evaluations->first(fn ($e) => $e->type === \App\Enums\TrainingPlan\EvaluationType::SUPERVISOR_REVIEW);

        return [
            'uuid'               => $a->uuid,
            'competency'         => $a->competency ? ['uuid' => $a->competency->uuid, 'name' => $a->competency->name] : null,
            'method'             => self::option($a->method) + ['effectiveness_check' => $a->method->effectivenessCheck()],
            'status'             => self::option($a->status),
            'description'        => $a->description,
            'due_on'             => $a->due_on?->toDateString(),
            'completed_on'       => $a->completed_on?->toDateString(),
            'outcome'            => $a->outcome,
            'files'              => $a->evidenceFiles->map->payload()->values(),
            // The effectiveness check that finished it (SOP 5.3.6).
            'effectiveness'      => $a->effectiveness_result ? [
                'result'         => self::option($a->effectiveness_result),
                'verified_level' => $a->verified_level,
                'evaluated_on'   => $a->evaluated_on?->toDateString(),
                'evaluated_by'   => $a->evaluator?->name,
            ] : null,
            'training'           => $item ? [
                'uuid'   => $item->uuid,
                'title'  => $item->title,
                'year'   => $item->plan?->year,
                'status' => ['value' => $item->status->value, 'label' => $item->status->label()],
                'completed_at' => $item->completed_at?->toDateString(),
                // The supervisor's view of whether it's applied on the job: input to the effectiveness check.
                'review' => $review?->isSubmitted() ? [
                    'applied_on_job' => $review->applied_on_job,
                    'rating'         => $review->rating,
                    'comment'        => $review->comment,
                ] : null,
            ] : null,
        ];
    }

    public static function option(?\BackedEnum $enum): ?array
    {
        return $enum ? ['value' => $enum->value, 'label' => $enum->label()] : null;
    }

    /** The rating scale, for forms and legends. */
    public static function levels(): array
    {
        return collect(CompetencyLevel::cases())->map(fn (CompetencyLevel $l) => [
            'value' => $l->value, 'label' => $l->label(), 'description' => $l->description(),
        ])->all();
    }
}
