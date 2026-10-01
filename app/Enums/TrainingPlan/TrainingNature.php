<?php

namespace App\Enums\TrainingPlan;

enum TrainingNature: string
{
    case STRATEGIC = 'strategic';
    case COMPULSORY = 'compulsory';
    case TECHNICAL_DEVELOPMENT = 'technical_development';
    case PROFESSIONAL_ENHANCEMENT = 'professional_enhancement';
    case OTHERS = 'others';

    public function label(): string
    {
        return match ($this) {
            self::STRATEGIC                => 'I - Strategic',
            self::COMPULSORY               => 'II - Compulsory/Mandatory',
            self::TECHNICAL_DEVELOPMENT    => 'III - Technical Development',
            self::PROFESSIONAL_ENHANCEMENT => 'IV - Professional Enhancement',
            self::OTHERS                   => 'V - Others',
        };
    }

    /** Matches spreadsheet labels loosely ("IV -Professional Enhancement", "V - Others ") by roman numeral. */
    public static function fromLabel(string $label): ?self
    {
        $numeral = strtoupper(trim(explode('-', trim($label))[0]));

        return match ($numeral) {
            'I'   => self::STRATEGIC,
            'II'  => self::COMPULSORY,
            'III' => self::TECHNICAL_DEVELOPMENT,
            'IV'  => self::PROFESSIONAL_ENHANCEMENT,
            'V'   => self::OTHERS,
            default => null,
        };
    }
}
