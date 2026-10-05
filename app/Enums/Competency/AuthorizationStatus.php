<?php

namespace App\Enums\Competency;

enum AuthorizationStatus: string
{
    /** Recommended by a supervisor or assessor; waiting for someone who grants. */
    case RECOMMENDED = 'recommended';
    case AUTHORIZED = 'authorized';
    /** Paused automatically when competence or a certificate lapses; reinstated by granting again. */
    case SUSPENDED = 'suspended';
    case DECLINED = 'declined';
    case REVOKED = 'revoked';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::RECOMMENDED => 'Recommended',
            self::AUTHORIZED  => 'Authorized',
            self::SUSPENDED   => 'Suspended',
            self::DECLINED    => 'Declined',
            self::REVOKED     => 'Revoked',
            self::EXPIRED     => 'Expired',
        };
    }

    /** Still in play: an employee has at most one of these per activity. */
    public function isCurrent(): bool
    {
        return in_array($this, [self::RECOMMENDED, self::AUTHORIZED, self::SUSPENDED], true);
    }

    /** @return string[] */
    public static function currentValues(): array
    {
        return [self::RECOMMENDED->value, self::AUTHORIZED->value, self::SUSPENDED->value];
    }
}
