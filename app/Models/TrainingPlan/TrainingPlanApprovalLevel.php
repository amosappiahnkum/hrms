<?php

namespace App\Models\TrainingPlan;

use App\Models\AppModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One level of training plan sign-off, e.g. "HSE validation". Levels are signed in `position`
 * order; the last one gives final approval. With rule "all" everyone in the level signs, with
 * "any" one of them is enough.
 */
class TrainingPlanApprovalLevel extends AppModel
{
    use SoftDeletes;

    public const RULES = ['all', 'any'];

    protected $fillable = ['name', 'position', 'rule'];

    protected $casts = ['position' => 'integer'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'training_plan_approval_level_user');
    }

    /** The configured chain, as a plan keeps it when submitted. */
    public static function snapshot(): array
    {
        return static::with('users:id')->orderBy('position')->orderBy('id')->get()
            ->map(fn (self $level) => [
                'name'     => $level->name,
                'rule'     => $level->rule,
                'user_ids' => $level->users->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ])->values()->all();
    }
}
