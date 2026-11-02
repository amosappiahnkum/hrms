<?php

namespace App\Enums\TrainingPlan;

/** Prepared → validated → approved, each step by a different person. Rejection returns it for editing. */
enum ApprovalStatus: string
{
    case DRAFT = 'draft';
    case PENDING_VALIDATION = 'pending_validation';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT              => 'Draft',
            self::PENDING_VALIDATION => 'Pending Validation',
            self::PENDING_APPROVAL   => 'Pending Approval',
            self::APPROVED           => 'Approved',
            self::REJECTED           => 'Rejected',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::REJECTED], true);
    }
}
