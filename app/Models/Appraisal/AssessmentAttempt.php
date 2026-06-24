<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Models\QuestionResponse;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AssessmentAttempt extends ApplicationModel
{
    use HasUuid;

    // Non-appraisal types go: draft → submitted
    // Appraisal type goes: draft → pending_supervisor → supervisor_confirmed → completed
    //   supervisor can return → employee revises → pending_supervisor again
    const string STATUS_DRAFT = 'draft';
    const string STATUS_PENDING_SUPERVISOR = 'pending_supervisor';
    const string STATUS_SUPERVISOR_CONFIRMED = 'supervisor_confirmed';
    const string STATUS_COMPLETED = 'completed';
    const string STATUS_RETURNED = 'returned';
    const string STATUS_SUBMITTED = 'submitted'; // non-appraisal final state

    protected $fillable = [
        'assessment_window_id',
        'user_id',
        'status',
        'started_at',
        'submitted_at',
        'employee_comment',
        'supervisor_id',
        'supervisor_comment',
        'supervisor_confirmed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'supervisor_confirmed_at' => 'datetime',
    ];

    public function window(): BelongsTo
    {
        return $this->belongsTo(AssessmentWindow::class, 'assessment_window_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function responses(): MorphMany
    {
        return $this->morphMany(QuestionResponse::class, 'respondable');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_RETURNED]);
    }

    public function isAppraisal(): bool
    {
        return $this->window?->assessment?->type === 'appraisal';
    }
}
