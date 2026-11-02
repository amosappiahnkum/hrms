<?php

namespace App\Models\TrainingPlan;

use App\Models\AppModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One person's decision at one approval level of a plan's submission round. */
class TrainingPlanSignoff extends AppModel
{
    use SoftDeletes;

    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = ['training_plan_id', 'round', 'level', 'user_id', 'decision', 'comment'];

    protected $casts = ['round' => 'integer', 'level' => 'integer'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TrainingPlan::class, 'training_plan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
