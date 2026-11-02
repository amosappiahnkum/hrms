<?php

namespace App\Enums\Competency;

/** The competency types of the Competency Management Procedure (AI-IMS-SOP-HR-003, 5.2). */
enum CompetencyGroup: string
{
    case EDUCATIONAL = 'educational';
    case TECHNICAL = 'technical';
    case PROCESS = 'process';
    case IMS = 'ims';
    case BEHAVIOURAL = 'behavioural';
    case LEADERSHIP = 'leadership';
    case CLIENT = 'client';
    case LEGAL = 'legal';

    public function label(): string
    {
        return match ($this) {
            self::EDUCATIONAL => 'Educational',
            self::TECHNICAL   => 'Technical',
            self::PROCESS     => 'Process-specific',
            self::IMS         => 'IMS & HSE',
            self::BEHAVIOURAL => 'Behavioural',
            self::LEADERSHIP  => 'Leadership & Supervision',
            self::CLIENT      => 'Client-specific',
            self::LEGAL       => 'Legal/Regulatory',
        };
    }
}
