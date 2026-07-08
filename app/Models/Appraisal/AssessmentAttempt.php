<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Models\QuestionResponse;
use App\Models\Training\ChapterProgress;
use App\Models\Training\CourseEnrollment;
use App\Models\Training\CourseChapter;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AssessmentAttempt extends ApplicationModel
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

    protected $fillable = [
        'assessment_window_id',
        'assessment_id',
        'course_enrollment_id',
        'course_chapter_id',
        'user_id',
        'status',
        'started_at',
        'submitted_at',
        'employee_comment',
        'supervisor_id',
        'supervisor_comment',
        'supervisor_confirmed_at',
        'score',
        'self_score',
        'kpi_score',
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

    public function events(): HasMany
    {
        return $this->hasMany(AssessmentAttemptEvent::class, 'assessment_attempt_id')
            ->orderBy('created_at');
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(AppraisalKpi::class, 'assessment_attempt_id')
            ->orderBy('order');
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

        $usagesByQId = ($this->relationLoaded('window') && $this->window?->relationLoaded('questionUsages'))
            ? $this->window->questionUsages->keyBy('question_id')
            : collect();

        // ── Weighted scores ──────────────────────────────────────────────────
        $totalWeight   = 0;
        $weightedScore = 0;
        $selfWeighted  = 0;
        $hasScores     = false;

        foreach ($responses as $r) {
            $effective = $r->supervisor_score ?? $r->score;
            $self      = $r->score;
            if ($effective === null && $self === null) continue;

            $weight = (float) ($usagesByQId[$r->question_id]?->custom_weight ?? $r->question?->weight ?? 1);

            $totalWeight   += $weight;
            $weightedScore += ($effective ?? 0) * $weight;
            $selfWeighted  += ($self ?? 0) * $weight;
            $hasScores      = true;
        }

        $overallScore = $hasScores && $totalWeight > 0
            ? round($weightedScore / $totalWeight, 2)
            : $this->score;

        $selfScoreVal = $hasScores && $totalWeight > 0
            ? round($selfWeighted / $totalWeight, 2)
            : $this->self_score;

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
        $total          = $responses->count();
        $overriddenCount = $responses->filter(fn ($r) => $r->supervisor_score !== null)->count();
        $overrideRate   = $total > 0 ? round(($overriddenCount / $total) * 100, 1) : null;

        return [
            'overall_score'            => $overallScore,
            'self_score'               => $selfScoreVal,
            'score_gap'                => $scoreGap,
            'category_scores'          => $categoryScores,
            'kpi_achievement_rate'     => $kpiAchievementRate,
            'revision_count'           => $revisionCount,
            'submission_days'          => $submissionDays,
            'supervisor_override_rate' => $overrideRate,
        ];
    }
}
