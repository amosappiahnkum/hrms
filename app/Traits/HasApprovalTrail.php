<?php

namespace App\Traits;

use App\Enums\TrainingPlan\ApprovalStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prepared / validated / approved / rejected stamps shared by training plans and their items.
 * Transitions are enforced by App\Services\TrainingPlan\ApprovalService.
 */
trait HasApprovalTrail
{
    public function initializeHasApprovalTrail(): void
    {
        $this->mergeFillable([
            'approval_status',
            'prepared_by', 'prepared_at',
            'validated_by', 'validated_at',
            'approved_by', 'approved_at',
            'rejected_by', 'rejected_at', 'rejection_comment',
        ]);

        $this->mergeCasts([
            'approval_status' => ApprovalStatus::class,
            'prepared_at'     => 'datetime',
            'validated_at'    => 'datetime',
            'approved_at'     => 'datetime',
            'rejected_at'     => 'datetime',
        ]);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === ApprovalStatus::APPROVED;
    }
}
