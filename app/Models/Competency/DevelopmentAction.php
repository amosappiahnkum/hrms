<?php

namespace App\Models\Competency;

use App\Enums\Competency\DevelopmentMethod;
use App\Enums\Competency\DevelopmentStatus;
use App\Enums\Competency\EffectivenessResult;
use App\Enums\TrainingPlan\TrainingNeedSource;
use App\Models\AppModel;
use App\Models\SelfService\Employee;
use App\Models\TrainingPlan\TrainingPlanItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** What will be done to close one of an employee's competency gaps: training, coaching, shadowing… */
class DevelopmentAction extends AppModel
{
    use SoftDeletes;

    protected $table = 'competency_development_actions';

    protected $fillable = [
        'employee_id', 'competency_id', 'competency_assessment_id', 'training_plan_item_id',
        'method', 'status', 'description', 'due_on', 'completed_on', 'outcome', 'created_by',
        'effectiveness_result', 'verified_level', 'competency_rating_id', 'evaluated_by', 'evaluated_on',
    ];

    protected $casts = [
        'method'       => DevelopmentMethod::class,
        'status'       => DevelopmentStatus::class,
        'due_on'       => 'date',
        'completed_on' => 'date',
        'effectiveness_result' => EffectivenessResult::class,
        'verified_level'       => 'integer',
        'evaluated_on'         => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    /** The assessment that found the gap. */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CompetencyAssessment::class, 'competency_assessment_id');
    }

    /** The rating that re-verified the competency when the action was evaluated. */
    public function verifiedRating(): BelongsTo
    {
        return $this->belongsTo(CompetencyRating::class, 'competency_rating_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'evaluated_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /** Files evidencing competence. */
    public function evidenceFiles(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(CompetencyEvidenceFile::class, 'evidenceable')->oldest('id');
    }

    public function trainingPlanItem(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanItem::class);
    }

    /**
     * Name the assessment that found the gap as the linked plan line's supporting record, in place
     * of the generic "Competency Matrix". Only on lines not yet approved: it is a planned field, and
     * changing it on an approved line would need re-approval.
     */
    public function recordTrainingSource(): void
    {
        $item = $this->trainingPlanItem;
        $assessment = $this->assessment;

        if (!$item || !$assessment?->assessed_on || $item->isApproved()) {
            return;
        }

        $generic = TrainingNeedSource::COMPETENCY_GAP_ANALYSIS->supportingRecord();
        if (in_array(trim((string) $item->supporting_record), ['', $generic], true)) {
            $item->update(['supporting_record' => "{$generic} – assessment of {$assessment->assessed_on->format('j M Y')}"]);
        }
    }

    /** Open training or certification actions not yet in a training plan: needs for the training team. */
    public function scopeAwaitingTraining(Builder $query): Builder
    {
        return $query->open()
            ->whereIn('method', [DevelopmentMethod::TRAINING, DevelopmentMethod::CERTIFICATION])
            ->whereNull('training_plan_item_id')
            ->whereHas('employee')
            ->whereHas('competency');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', DevelopmentStatus::openValues());
    }
}
