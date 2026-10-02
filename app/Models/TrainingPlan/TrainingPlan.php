<?php

namespace App\Models\TrainingPlan;

use App\Models\AppModel;
use App\Models\User;
use App\Traits\HasApprovalTrail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The annual training plan (one per year): its items and sign-offs.
 *
 * HR may open a collection window, during which heads of department add the trainings their staff
 * need. HR then reviews everything and submits the plan through the configured approval levels.
 */
class TrainingPlan extends AppModel
{
    use SoftDeletes, HasApprovalTrail;

    protected $fillable = [
        'year',
        'title',
        'budget_factor',
        'collection_starts_on',
        'collection_ends_on',
        'approval_chain',
        'current_level',
        'approval_round',
        'created_by',
    ];

    protected $attributes = [
        'approval_status' => 'draft',
        'approval_round'  => 0,
    ];

    protected $casts = [
        'year'                 => 'integer',
        'budget_factor'        => 'decimal:2',
        'collection_starts_on' => 'date',
        'collection_ends_on'   => 'date',
        'approval_chain'       => 'array',
        'current_level'        => 'integer',
        'approval_round'       => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(TrainingPlanItem::class);
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(TrainingPlanSignoff::class);
    }

    /** Heads of department can add trainings: the plan is being prepared and today is in the window. */
    public function isCollecting(): bool
    {
        return $this->approval_status->isEditable()
            && $this->collection_starts_on && $this->collection_ends_on
            && today()->betweenIncluded($this->collection_starts_on, $this->collection_ends_on);
    }

    /** The level the plan is waiting on, from the chain it was submitted with. */
    public function currentLevel(): ?array
    {
        return $this->current_level === null ? null : ($this->approval_chain[$this->current_level] ?? null);
    }

    public function isFinalLevel(): bool
    {
        return $this->current_level !== null && $this->current_level === count($this->approval_chain ?? []) - 1;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
