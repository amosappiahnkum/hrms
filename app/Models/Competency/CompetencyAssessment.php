<?php

namespace App\Models\Competency;

use App\Models\AppModel;
use App\Models\Position;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An employee rated against their position's competencies. The latest completed one is their current state. */
class CompetencyAssessment extends AppModel
{
    use SoftDeletes;

    public const DRAFT = 'draft';
    public const COMPLETED = 'completed';

    protected $fillable = [
        'employee_id', 'position_id', 'assessor_id', 'status',
        'assessed_on', 'next_review_on', 'comment', 'completed_at',
    ];

    protected $casts = [
        'assessed_on'    => 'date',
        'next_review_on' => 'date',
        'completed_at'   => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(CompetencyRating::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::COMPLETED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
