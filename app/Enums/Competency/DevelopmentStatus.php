<?php

namespace App\Enums\Competency;

enum DevelopmentStatus: string
{
    case PLANNED = 'planned';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PLANNED     => 'Planned',
            self::IN_PROGRESS => 'In progress',
            self::DONE        => 'Done',
            self::CANCELLED   => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::PLANNED, self::IN_PROGRESS], true);
    }
}
