<?php

namespace App\Enums\Competency;

/** How effective a development action proved when competence was re-verified (SOP 5.3.6). */
enum EffectivenessResult: string
{
    case EFFECTIVE = 'effective';
    case PARTIALLY = 'partially';
    case NOT_EFFECTIVE = 'not_effective';

    public function label(): string
    {
        return match ($this) {
            self::EFFECTIVE     => 'Effective',
            self::PARTIALLY     => 'Partially effective',
            self::NOT_EFFECTIVE => 'Not effective',
        };
    }
}
