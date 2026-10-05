<?php

namespace App\Models\TrainingPlan;

use App\Enums\TrainingPlan\PersonnelCategory;
use App\Enums\TrainingPlan\TrainingDelivery;
use App\Enums\TrainingPlan\TrainingNature;
use App\Enums\TrainingPlan\TrainingNeedSource;
use App\Enums\TrainingPlan\TrainingStatus;
use App\Models\AppModel;
use App\Models\EmployeeCertification;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Traits\HasApprovalTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One employee's planned training within a plan. */
class TrainingPlanItem extends AppModel
{
    use SoftDeletes, HasApprovalTrail;

    /** Changing any of these on an approved item sends it back for validation and approval. */
    public const PLANNED_FIELDS = [
        'employee_id', 'training_catalogue_item_id', 'title', 'nature', 'training_domain_id', 'category',
        'source_of_need', 'supporting_record', 'quarter', 'days', 'cost', 'trainer', 'delivery',
    ];

    /** The training record: what actually happened. Recorded by HR once the training is approved. */
    public const ACTUAL_FIELDS = ['actual_start_date', 'actual_end_date', 'hours', 'attended', 'actual_cost', 'provider', 'score', 'passed'];

    /** Progress fields: editable at any time without re-approval. */
    public const PROGRESS_FIELDS = ['planned_start_date', 'planned_end_date', 'status', 'completed_at', 'comment', ...self::ACTUAL_FIELDS];

    public const QUARTERS = ['Q1', 'Q2', 'Q3', 'Q4'];

    protected $fillable = [
        'training_plan_id',
        ...self::PLANNED_FIELDS,
        ...self::PROGRESS_FIELDS,
        'created_by',
    ];

    protected $casts = [
        'nature'             => TrainingNature::class,
        'category'           => PersonnelCategory::class,
        'source_of_need'     => TrainingNeedSource::class,
        'delivery'           => TrainingDelivery::class,
        'status'             => TrainingStatus::class,
        'days'               => 'decimal:1',
        'cost'               => 'decimal:2',
        'planned_start_date' => 'date',
        'planned_end_date'   => 'date',
        'completed_at'       => 'date',
        'actual_start_date'  => 'date',
        'actual_end_date'    => 'date',
        'hours'              => 'decimal:1',
        'attended'           => 'boolean',
        'actual_cost'        => 'decimal:2',
        'score'              => 'decimal:2',
        'passed'             => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TrainingPlan::class, 'training_plan_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(TrainingCatalogueItem::class, 'training_catalogue_item_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(TrainingDomain::class, 'training_domain_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(TrainingEvaluation::class);
    }

    /**
     * Completing a training asks for its evaluations; correcting it away from completed withdraws the
     * ones nobody answered. Linked development actions follow the training's outcome. Here rather than in the controller, so single and bulk updates both do it.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::updated(function (TrainingPlanItem $item) {
            if (!$item->wasChanged('status')) {
                return;
            }

            $service = app(\App\Services\TrainingPlan\TrainingEvaluationService::class);
            if ($item->status === TrainingStatus::COMPLETED) {
                $service->schedule($item);
            } elseif ($item->getOriginal('status') === TrainingStatus::COMPLETED) {
                $service->withdraw($item);
            }

            // Development actions waiting on this training move on: to their effectiveness check, or back to planning.
            app(\App\Services\Competency\EffectivenessService::class)->trainingEnded($item);
        });
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', 'approved');
    }
}
