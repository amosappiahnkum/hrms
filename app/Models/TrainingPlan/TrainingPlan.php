<?php

namespace App\Models\TrainingPlan;

use App\Models\AppModel;
use App\Models\User;
use App\Traits\HasApprovalTrail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The annual training plan (one per year): sign-offs and its items. */
class TrainingPlan extends AppModel
{
    use SoftDeletes, HasApprovalTrail;

    protected $fillable = [
        'year',
        'title',
        'budget_factor',
        'created_by',
    ];

    protected $casts = [
        'year'          => 'integer',
        'budget_factor' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(TrainingPlanItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
