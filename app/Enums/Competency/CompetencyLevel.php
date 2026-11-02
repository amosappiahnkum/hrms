<?php

namespace App\Enums\Competency;

/** The rating scale (procedure 5.3.4). "Not applicable" is stored as no level. */
enum CompetencyLevel: int
{
    case NOVICE = 0;
    case AWARENESS = 1;
    case DEVELOPING = 2;
    case COMPETENT = 3;
    case PROFICIENT = 4;

    public function label(): string
    {
        return match ($this) {
            self::NOVICE     => 'Novice',
            self::AWARENESS  => 'Awareness',
            self::DEVELOPING => 'Developing',
            self::COMPETENT  => 'Competent',
            self::PROFICIENT => 'Proficient',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NOVICE     => 'No prior knowledge in the area.',
            self::AWARENESS  => 'Understands basic principles but requires supervision.',
            self::DEVELOPING => 'Can perform with supervision or coaching.',
            self::COMPETENT  => 'Can independently perform to the required standard.',
            self::PROFICIENT => 'Advanced: can guide, coach or assess others where authorised.',
        };
    }
}
