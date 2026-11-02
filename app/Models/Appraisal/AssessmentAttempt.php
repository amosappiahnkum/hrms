<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Models\EmployeeCertification;
use App\Models\QuestionBank\QuestionUsage;
use App\Models\QuestionResponse;
use App\Models\Training\ChapterProgress;
use App\Models\Training\CourseEnrollment;
use App\Models\Training\CourseChapter;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AssessmentAttempt extends ApplicationModel implements \App\Contracts\ApprovalSubject
{
    use HasUuid;

    // Non-appraisal types go: draft → submitted
    // Appraisal type goes:
    //   draft → pending_supervisor → pending_employee_acknowledgment → supervisor_confirmed
    //   → pending_signatures (both employee + supervisor must sign) → completed
    //   supervisor can return at pending_supervisor → employee revises → pending_supervisor again
    const string STATUS_DRAFT = 'draft';
    const string STATUS_PENDING_SUPERVISOR = 'pending_supervisor';
    const string STATUS_PENDING_EMPLOYEE_ACKNOWLEDGMENT = 'pending_employee_acknowledgment';
    const string STATUS_SUPERVISOR_CONFIRMED = 'supervisor_confirmed';
    const string STATUS_PENDING_SIGNATURES = 'pending_signatures';
    const string STATUS_COMPLETED = 'completed';
    const string STATUS_RETURNED = 'returned';
    const string STATUS_SUBMITTED = 'submitted'; // non-appraisal final state
    // After the employee acknowledges, the heads above the appraiser endorse it in turn before HR (when set up).
    const string STATUS_PENDING_ENDORSEMENT = 'pending_endorsement';

    // Review flow, fixed when the appraisal is first submitted (setting `appraisal.review_flow`):
    //   acknowledge (also null): the appraiser's review goes back to the employee to accept or dispute, then on.
    //   direct: the appraiser confirms and it goes on (endorsement, then HR); the employee never sees the
    //   appraiser's changes or comment. HR sees every change (QuestionResponseRevision).
    const string REVIEW_FLOW_ACKNOWLEDGE = 'acknowledge';
    const string REVIEW_FLOW_DIRECT = 'direct';

    /** Events the employee doesn't see in the direct flow, and the ones whose comment they still see. */
    private const array REVIEW_ONLY_EVENTS = ['answers_edited', 'endorsement_returned'];
    private const array EMPLOYEE_VISIBLE_COMMENTS = ['submitted', 'returned'];

    protected $fillable = [
        'assessment_window_id',
        'assessment_id',
        'course_enrollment_id',
        'course_chapter_id',
        'user_id',
        'status',
        'review_flow',
        'started_at',
        'submitted_at',
        'employee_comment',
        'supervisor_id',
        'supervisor_comment',
        'supervisor_confirmed_at',
        'score',
        'self_score',
        'kpi_score',
        'employee_section_score',
        'supervisor_section_score',
        'finalized_at',
        'employee_signed_at',
        'supervisor_signed_at',
    ];

    protected $casts = [
        'started_at'              => 'datetime',
        'submitted_at'            => 'datetime',
        'supervisor_confirmed_at' => 'datetime',
        'finalized_at'            => 'datetime',
        'employee_signed_at'      => 'datetime',
        'supervisor_signed_at'    => 'datetime',
        'score'                   => 'decimal:2',
        'self_score'              => 'decimal:2',
        'kpi_score'               => 'decimal:2',
        'employee_section_score'  => 'decimal:2',
        'supervisor_section_score' => 'decimal:2',
    ];

    public function window(): BelongsTo
    {
        return $this->belongsTo(AssessmentWindow::class, 'assessment_window_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function responses(): MorphMany
    {
        return $this->morphMany(QuestionResponse::class, 'respondable');
    }

    /** The endorsement by the heads above the appraiser (the latest, when it was returned and sent again). */
    public function approval(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(\App\Models\Payroll\Approval::class, 'subject')->latestOfMany();
    }

    public function adjustableValues(): array
    {
        return [];
    }

    public function applyAdjustments(array $values): void
    {
    }

    public function approvalFinished(\App\Models\Payroll\Approval $approval): void
    {
        app(\App\Services\Appraisal\AppraisalEndorsement::class)->finished($this, $approval);
    }

    public function approvalSummary(): string
    {
        return "Appraisal: {$this->window?->title}";
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssessmentAttemptEvent::class, 'assessment_attempt_id')
            ->orderBy('created_at');
    }

    /** Targets for the period under review, with the actuals the supervisor records — the KPIs that are scored. */
    public function kpis(): HasMany
    {
        return $this->hasMany(AppraisalKpi::class, 'assessment_attempt_id')
            ->orderBy('order');
    }

    /** Targets agreed in this appraisal for the next period: the employee's KPI plan for it. */
    public function nextKpis(): HasMany
    {
        return $this->hasMany(EmployeeKpi::class, 'source_attempt_id')
            ->orderBy('order');
    }

    /** The period after the one under review, of the same length (in whole months). */
    public function nextPeriod(): array
    {
        [$from, $to] = $this->window->appraisalPeriod();
        $start = $to->addDay()->startOfDay();

        return [$start, $start->addMonths(max(1, (int) round($from->diffInMonths($to))))->subDay()];
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(AppraisalTraining::class, 'assessment_attempt_id')
            ->orderBy('order');
    }

    /**
     * Seed the Training & Development section with courses completed and
     * certifications received during the appraised period. Runs once per attempt.
     */
    public function prefillTrainings(): void
    {
        $window = $this->window;

        if (!$window?->assessment?->include_training_section || $this->trainings()->exists()) {
            return;
        }

        [$from, $to] = $window->appraisalPeriod();
        $rows = collect();

        CourseEnrollment::with('course')
            ->where('user_id', $this->user_id)
            ->where('status', CourseEnrollment::STATUS_COMPLETED)
            ->whereBetween('completed_at', [$from, $to])
            ->get()
            ->each(fn (CourseEnrollment $e) => $rows->push([
                'subject'              => $e->course?->title ?? 'Course',
                'type'                 => 'course',
                'start_date'           => $e->enrolled_at?->toDateString(),
                'end_date'             => $e->completed_at?->toDateString(),
                'source'               => AppraisalTraining::SOURCE_INTERNAL,
                'course_enrollment_id' => $e->id,
            ]));

        if ($employeeId = $this->user?->employee_id) {
            EmployeeCertification::where('employee_id', $employeeId)
                ->whereBetween('date_received', [$from->toDateString(), $to->toDateString()])
                ->get()
                ->each(fn (EmployeeCertification $c) => $rows->push([
                    'subject'                   => $c->title,
                    'type'                      => 'certification',
                    'start_date'                => $c->date_received?->toDateString(),
                    'end_date'                  => $c->date_received?->toDateString(),
                    'source'                    => AppraisalTraining::SOURCE_CERTIFICATION,
                    'employee_certification_id' => $c->id,
                ]));
        }

        $rows->sortBy('start_date')->values()->each(
            fn ($row, $i) => $this->trainings()->create([...$row, 'order' => $i])
        );
    }

    /**
     * Start this appraisal's KPIs from the employee's KPI plan for the period under review. Actuals start empty for the
     * supervisor to record. Runs only while the attempt has no KPIs of its own.
     */
    public function startKpisFromPlan(): void
    {
        $employeeId = $this->user?->employee_id;
        if (!$employeeId || $this->kpis()->exists()) {
            return;
        }

        [$from, $to] = $this->window->appraisalPeriod();
        EmployeeKpi::where('employee_id', $employeeId)->overlapping($from, $to)
            ->orderBy('period_start')->orderBy('order')->get()
            ->each(fn (EmployeeKpi $kpi, int $i) => AppraisalKpi::create([
                'assessment_attempt_id' => $this->id,
                'description'           => $kpi->description,
                'target'                => $kpi->target,
                'actual'                => null,
                'order'                 => $i,
            ]));
    }

    // ── Training quiz relations ───────────────────────────────────────────────

    public function directAssessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'course_enrollment_id');
    }

    public function trainingChapter(): BelongsTo
    {
        return $this->belongsTo(CourseChapter::class, 'course_chapter_id');
    }

    // ── State helpers ─────────────────────────────────────────────────────────

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_RETURNED]);
    }

    public function isDirectReview(): bool
    {
        return $this->review_flow === self::REVIEW_FLOW_DIRECT;
    }

    /** Whether the appraiser's changes and comments are kept from this viewer: the employee, in the direct flow. */
    public function hidesReviewFrom(?int $viewerId): bool
    {
        return $this->isDirectReview() && $viewerId !== null && $viewerId === $this->user_id;
    }

    /**
     * The supervisor's comment as this viewer may see it. In the direct flow the employee sees it only while it's the
     * reason the appraisal was returned to them.
     */
    public function supervisorCommentFor(?int $viewerId): ?string
    {
        if ($this->hidesReviewFrom($viewerId) && $this->status !== self::STATUS_RETURNED) {
            return null;
        }

        return $this->supervisor_comment;
    }

    /** The timeline as this viewer may see it (needs `events` loaded). */
    public function eventsVisibleTo(?int $viewerId): \Illuminate\Support\Collection
    {
        if (!$this->hidesReviewFrom($viewerId)) {
            return $this->events;
        }

        return $this->events
            ->reject(fn ($e) => in_array($e->event_type, self::REVIEW_ONLY_EVENTS, true))
            ->map(fn ($e) => in_array($e->event_type, self::EMPLOYEE_VISIBLE_COMMENTS, true) ? $e : (clone $e)->setAttribute('comment', null))
            ->values();
    }

    /** The window's questions by section: who answers them (QuestionUsage::RESPONDENT_*). */
    public function questionUsagesFor(string $respondent): \Illuminate\Support\Collection
    {
        $this->loadMissing('window.questionUsages');

        return ($this->window?->questionUsages ?? collect())
            ->filter(fn (QuestionUsage $u) => ($u->respondent ?? QuestionUsage::RESPONDENT_EMPLOYEE) === $respondent)
            ->values();
    }

    /**
     * Whether this viewer sees the supervisor's own section. Everyone but the employee does; the employee only in the
     * acknowledge flow, once the supervisor has finished it.
     */
    public function showsSupervisorSectionTo(?int $viewerId): bool
    {
        if ($viewerId === null || $viewerId !== $this->user_id) {
            return true;
        }

        return !$this->isDirectReview()
            && !in_array($this->status, [self::STATUS_DRAFT, self::STATUS_RETURNED, self::STATUS_PENDING_SUPERVISOR], true);
    }

    /**
     * The scores, live: the employee's section (their answers, with the supervisor's changes), the employee's own
     * answers alone (self), the supervisor's section, and the overall score — the two sections split by the template's
     * supervisor_weight (default 60%). With only one section scored (no supervisor questions, or nobody above the
     * employee to answer them), the overall score is that section's. Weighted by each question's weight.
     */
    public function sectionScores(): array
    {
        $this->loadMissing(['responses.question', 'window.questionUsages', 'window.assessment']);
        $usages = ($this->window?->questionUsages ?? collect())->keyBy('question_id');
        $sums = ['employee' => [0.0, 0.0], 'self' => [0.0, 0.0], 'supervisor' => [0.0, 0.0]];
        $add = function (string $key, ?int $score, float $weight) use (&$sums) {
            if ($score !== null) {
                $sums[$key][0] += $score * $weight;
                $sums[$key][1] += $weight;
            }
        };

        foreach ($this->responses as $r) {
            $usage  = $usages->get($r->question_id);
            $weight = (float) ($usage?->custom_weight ?? $r->question?->weight ?? 1);
            if ($usage?->isForSupervisor()) {
                $add('supervisor', $r->score, $weight);
                continue;
            }
            $add('employee', $r->supervisor_score ?? $r->score, $weight);
            $add('self', $r->score, $weight);
        }

        $avg = fn (array $sum) => $sum[1] > 0 ? round($sum[0] / $sum[1], 2) : null;
        $employee   = $avg($sums['employee']);
        $supervisor = $avg($sums['supervisor']);
        $share      = ($this->window?->assessment?->supervisor_weight ?? 60) / 100;

        return [
            'score'                    => $employee !== null && $supervisor !== null
                ? round($employee * (1 - $share) + $supervisor * $share, 2)
                : ($employee ?? $supervisor),
            'self_score'               => $avg($sums['self']),
            'employee_section_score'   => $employee,
            'supervisor_section_score' => $supervisor,
        ];
    }

    public function isTrainingQuiz(): bool
    {
        return !is_null($this->course_enrollment_id);
    }

    public function isAppraisal(): bool
    {
        if ($this->isTrainingQuiz()) {
            return false;
        }
        return $this->window?->assessment?->type === 'appraisal';
    }

    /**
     * Called after a training quiz attempt is submitted.
     * Records the attempt UUID in chapter_progress and triggers completion check.
     */
    public function handleTrainingQuizSubmission(): void
    {
        $enrollment = $this->enrollment ?? $this->load('enrollment')->enrollment;

        if ($this->course_chapter_id) {
            ChapterProgress::updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'chapter_id'    => $this->course_chapter_id,
                ],
                ['quiz_attempt_id' => $this->id]
            );
        }

        // Score the attempt: weighted average of response scores (0–5 scale)
        $this->loadMissing(['responses', 'directAssessment.questionUsages']);
        $score = $this->computeTrainingScore();
        if ($score !== null) {
            $this->update(['score' => $score]);
        }

        $enrollment->refresh();
        $enrollment->checkCompletion();
    }

    /**
     * Weighted point total for training quiz auto-grading.
     * Each response's score × its question weight = points earned.
     * Open-text responses (score=null) are excluded.
     */
    private function computeTrainingScore(): ?float
    {
        $usages = $this->directAssessment?->questionUsages ?? collect();
        $usagesByQId = $usages->keyBy('question_id');

        $earned    = 0.0;
        $hasScores = false;

        foreach ($this->responses as $r) {
            if ($r->score === null) continue;
            $usage   = $usagesByQId->get($r->question_id);
            $weight  = (float) ($usage?->custom_weight ?? $r->question?->weight ?? 1);
            $earned += $r->score * $weight;
            $hasScores = true;
        }

        return $hasScores ? round($earned, 2) : null;
    }

    /**
     * Compute live KPI summary from loaded relations.
     * For completed attempts, falls back to frozen score/kpi_score for the aggregate fields.
     * Category scores, revision count, submission speed, and override rate are always live.
     *
     * Requires: responses.question.questionCategory, events, kpis, window.questionUsages
     */
    public function computeKpiSummary(): array
    {
        $responses = $this->relationLoaded('responses') ? $this->responses : collect();
        $events    = $this->relationLoaded('events') ? $this->events : collect();
        $kpis      = $this->relationLoaded('kpis') ? $this->kpis : collect();

        // ── Weighted scores (both sections) ─────────────────────────────────
        $scores       = $this->sectionScores();
        $overallScore = $scores['score'] ?? $this->score;
        $selfScoreVal = $scores['self_score'] ?? $this->self_score;

        // ── Self vs Supervisor Gap ───────────────────────────────────────────
        $scoreGap = ($overallScore !== null && $selfScoreVal !== null)
            ? round((float) $overallScore - (float) $selfScoreVal, 2)
            : null;

        // ── Score by Question Category ───────────────────────────────────────
        $categoryScores = $responses
            ->filter(fn ($r) => $r->question?->questionCategory !== null
                && ($r->supervisor_score ?? $r->score) !== null)
            ->groupBy(fn ($r) => $r->question->questionCategory->name)
            ->map(fn ($group) => round(
                $group->avg(fn ($r) => $r->supervisor_score ?? $r->score), 2
            ))
            ->sortKeys()
            ->all();

        // ── KPI Achievement Rate ─────────────────────────────────────────────
        $achievable = $kpis->filter(fn ($k) => (float) $k->target > 0 && $k->actual !== null);
        $kpiAchievementRate = $achievable->isNotEmpty()
            ? round($achievable->avg(fn ($k) => ((float) $k->actual / (float) $k->target) * 100), 2)
            : $this->kpi_score;

        // ── Revision Count ───────────────────────────────────────────────────
        $revisionCount = $events->where('event_type', 'returned')->count();

        // ── Submission Speed (days) ──────────────────────────────────────────
        $submissionDays = ($this->started_at && $this->submitted_at)
            ? round($this->started_at->diffInHours($this->submitted_at) / 24, 1)
            : null;

        // ── Supervisor Override Rate ─────────────────────────────────────────
        $supervisorQuestionIds = $this->questionUsagesFor(QuestionUsage::RESPONDENT_SUPERVISOR)->pluck('question_id')->all();
        $employeeResponses = $responses->reject(fn ($r) => in_array($r->question_id, $supervisorQuestionIds, true));
        $total          = $employeeResponses->count();
        $overriddenCount = $employeeResponses->filter(fn ($r) => $r->supervisor_score !== null)->count();
        $overrideRate   = $total > 0 ? round(($overriddenCount / $total) * 100, 1) : null;

        return [
            'overall_score'            => $overallScore,
            'self_score'               => $selfScoreVal,
            'employee_section_score'   => $scores['employee_section_score'],
            'supervisor_section_score' => $scores['supervisor_section_score'],
            'score_gap'                => $scoreGap,
            'category_scores'          => $categoryScores,
            'kpi_achievement_rate'     => $kpiAchievementRate,
            'revision_count'           => $revisionCount,
            'submission_days'          => $submissionDays,
            'supervisor_override_rate' => $overrideRate,
        ];
    }
}
