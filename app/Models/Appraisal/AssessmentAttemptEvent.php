<?php

namespace App\Models\Appraisal;

use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentAttemptEvent extends Model
{
    use HasUuid;

    protected $table = 'assessment_attempt_events';

    protected $fillable = [
        'assessment_attempt_id',
        'event_type',
        'actor_id',
        'comment',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'assessment_attempt_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
