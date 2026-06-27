<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Models\QuestionResponse;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AssessmentAttempt extends ApplicationModel
{
    use HasUuid;

    // Non-appraisal types go: draft → submitted
    // Appraisal type goes: draft → pending_supervisor → supervisor_confirmed → completed
    //   supervisor can return → employee revises → pending_supervisor again
    const string STATUS_DRAFT = 'draft';
    const string STATUS_PENDING_SUPERVISOR = 'pending_supervisor';
    const string STATUS_SUPERVISOR_CONFIRMED = 'supervisor_confirmed';
    const string STATUS_COMPLETED = 'completed';
    const string STATUS_RETURNED = 'returned';
    const string STATUS_SUBMITTED = 'submitted'; // non-appraisal final state

    protected $fillable = [
        'assessment_window_id',
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
    ];

    protected $casts = [
        'started_at'              => 'datetime',
        'submitted_at'            => 'datetime',
        'supervisor_confirmed_at' => 'datetime',
        'finalized_at'            => 'datetime',
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

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_RETURNED]);
    }

    public function isAppraisal(): bool
    {
        return $this->window?->assessment?->type === 'appraisal';
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
