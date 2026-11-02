<?php

namespace App\Models\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Models\AppModel;
use App\Models\SelfService\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One request going through its workflow (a snapshot of the steps, so later edits don't affect it). */
class Approval extends AppModel
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'subject_type', 'subject_id', 'process', 'approval_workflow_id', 'employee_id', 'status',
        'current_position', 'steps', 'current_approver_ids', 'distinct_approvers', 'started_by', 'completed_at',
    ];

    protected $casts = [
        'process'              => ApprovalProcess::class,
        'steps'                => 'array',
        'current_approver_ids' => 'array',
        'distinct_approvers'   => 'boolean',
        'completed_at'         => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class)->orderBy('id');
    }

    public function currentStep(): ?array
    {
        return $this->current_position === null ? null : collect($this->steps)->firstWhere('position', $this->current_position);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Waiting for this user's decision. */
    public function scopeWaitingFor(Builder $query, int $userId): Builder
    {
        return $query->where('status', self::PENDING)->whereJsonContains('current_approver_ids', $userId);
    }
}
