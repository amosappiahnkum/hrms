<?php

namespace App\Enums\TrainingPlan;

enum TrainingStatus: string
{
    case NOT_STARTED = 'not_started';
    case SCHEDULED = 'scheduled';
    case POSTPONED = 'postponed';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Not Started',
            self::SCHEDULED   => 'Scheduled',
            self::POSTPONED   => 'Postponed',
            self::CANCELLED   => 'Cancelled',
            self::COMPLETED   => 'Completed',
            self::FAILED      => 'Failed',
        };
    }

    /** Still expected to take place (reminders and overdue checks apply). */
    public function isOpen(): bool
    {
        return in_array($this, [self::NOT_STARTED, self::SCHEDULED], true);
    }
}
