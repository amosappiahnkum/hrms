<?php

namespace App\Enums\Payroll;

/** How a pay component's amount is worked out. */
enum ComponentCalculation: string
{
    case FIXED = 'fixed';
    case PERCENT_OF_BASIC = 'percent_of_basic';
    /** rate × (basic ÷ working days ÷ hours a day) per hour, e.g. 1.5 for weekday overtime. */
    case HOURLY_MULTIPLIER = 'hourly_multiplier';
    /** rate × quantity, e.g. per offshore day. */
    case RATE_PER_UNIT = 'rate_per_unit';
    /** Entered for each employee or pay run. */
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::FIXED             => 'Fixed amount',
            self::PERCENT_OF_BASIC  => 'Percentage of basic salary',
            self::HOURLY_MULTIPLIER => 'Multiple of the hourly rate',
            self::RATE_PER_UNIT     => 'Rate per unit (day, hour…)',
            self::MANUAL            => 'Entered each time',
        };
    }

    public function needsRate(): bool
    {
        return $this !== self::MANUAL;
    }

    /** Amounts in money (so a currency applies), rather than a percentage or multiplier. */
    public function isMoney(): bool
    {
        return in_array($this, [self::FIXED, self::RATE_PER_UNIT], true);
    }
}
