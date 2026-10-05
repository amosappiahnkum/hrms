<?php

namespace App\Models\TrainingPlan;

use App\Enums\TrainingPlan\EvaluationType;
use App\Models\AppModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One evaluation of a completed training: the participant's feedback, or their supervisor's review. */
class TrainingEvaluation extends AppModel
{
    use SoftDeletes;

    protected $fillable = [
        'training_plan_item_id', 'type', 'evaluator_id', 'due_on', 'notified_at', 'reminded_at',
        'submitted_at', 'submitted_by', 'rating', 'applied_on_job', 'answers', 'comment',
    ];

    protected $casts = [
        'type'           => EvaluationType::class,
        'due_on'         => 'date',
        'notified_at'    => 'datetime',
        'reminded_at'    => 'datetime',
        'submitted_at'   => 'datetime',
        'rating'         => 'integer',
        'applied_on_job' => 'boolean',
        'answers'        => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanItem::class, 'training_plan_item_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /** A supervisor review opens on its due date (the effect needs time to show); feedback opens at once. */
    public function isOpen(): bool
    {
        return !$this->isSubmitted()
            && ($this->type === EvaluationType::PARTICIPANT_FEEDBACK || !$this->due_on->isFuture());
    }

    public function status(): string
    {
        return match (true) {
            $this->isSubmitted()    => 'submitted',
            !$this->isOpen()        => 'upcoming',
            $this->due_on->isPast() && !$this->due_on->isToday() => 'overdue',
            default                 => 'due',
        };
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('submitted_at');
    }
}
