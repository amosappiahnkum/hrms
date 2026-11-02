<?php

namespace App\Enums\Payroll;

enum PayRunStatus: string
{
    case DRAFT = 'draft';
    case CALCULATED = 'calculated';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case PAID = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT            => 'Draft',
            self::CALCULATED       => 'Calculated',
            self::PENDING_APPROVAL => 'Awaiting approval',
            self::APPROVED         => 'Approved',
            self::PAID             => 'Paid',
        };
    }

    /** Can still be (re)calculated or removed. */
    public function isOpen(): bool
    {
        return in_array($this, [self::DRAFT, self::CALCULATED], true);
    }

    /** Approved or paid: final, and the rates it used are locked. */
    public function isFinal(): bool
    {
        return in_array($this, [self::APPROVED, self::PAID], true);
    }
}
