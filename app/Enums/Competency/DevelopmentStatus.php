<?php

namespace App\Enums\Competency;

enum DevelopmentStatus: string
{
    case PLANNED = 'planned';
    case IN_PROGRESS = 'in_progress';
    /** The development took place (e.g. its training was completed); its effectiveness is still to be checked. */
    case AWAITING_EVALUATION = 'awaiting_evaluation';
    /** Finished and evaluated (SOP 5.3.6). Only reached through the effectiveness check. */
    case DONE = 'done';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PLANNED             => 'Planned',
            self::IN_PROGRESS         => 'In progress',
            self::AWAITING_EVALUATION => 'Awaiting evaluation',
            self::DONE                => 'Done',
            self::CANCELLED           => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, self::openCases(), true);
    }

    /** @return self[] statuses of actions still working on a gap */
    public static function openCases(): array
    {
        return [self::PLANNED, self::IN_PROGRESS, self::AWAITING_EVALUATION];
    }

    /** @return string[] */
    public static function openValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::openCases());
    }
}
