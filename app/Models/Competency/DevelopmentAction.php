<?php

namespace App\Models\Competency;

use App\Enums\Competency\DevelopmentMethod;
use App\Enums\Competency\DevelopmentStatus;
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
    ];

    protected $casts = [
        'method'       => DevelopmentMethod::class,
        'status'       => DevelopmentStatus::class,
        'due_on'       => 'date',
        'completed_on' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function trainingPlanItem(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanItem::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [DevelopmentStatus::PLANNED, DevelopmentStatus::IN_PROGRESS]);
    }
}
