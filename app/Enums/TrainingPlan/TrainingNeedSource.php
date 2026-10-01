<?php

namespace App\Enums\TrainingPlan;

enum TrainingNeedSource: string
{
    case PERFORMANCE_APPRAISAL = 'performance_appraisal';
    case COMPETENCY_GAP_ANALYSIS = 'competency_gap_analysis';
    case SUCCESSION_PLAN = 'succession_plan';
    case LEGAL_REGULATORY = 'legal_regulatory';
    case CERTIFICATION_RENEWAL = 'certification_renewal';
    case CLIENT_REQUIREMENT = 'client_requirement';
    case CAREER_DEVELOPMENT = 'career_development';

    public function label(): string
    {
        return match ($this) {
            self::PERFORMANCE_APPRAISAL   => 'Performance Appraisal',
            self::COMPETENCY_GAP_ANALYSIS => 'Competency Gap Analysis',
            self::SUCCESSION_PLAN         => 'Succession Plan',
            self::LEGAL_REGULATORY        => 'Legal/Regulatory Requirement',
            self::CERTIFICATION_RENEWAL   => 'Certification Renewal',
            self::CLIENT_REQUIREMENT      => 'Client Requirement',
            self::CAREER_DEVELOPMENT      => 'Promotion/Career Development',
        };
    }

    /** The record that usually evidences this need (spreadsheet "Supporting Record" list). */
    public function supportingRecord(): string
    {
        return match ($this) {
            self::PERFORMANCE_APPRAISAL   => 'AI-HR-FM-09',
            self::COMPETENCY_GAP_ANALYSIS => 'Competency Matrix',
            self::SUCCESSION_PLAN         => 'Critical Role Succession Matrix',
            self::LEGAL_REGULATORY        => 'Legal Register',
            self::CERTIFICATION_RENEWAL   => 'Certification Register',
            self::CLIENT_REQUIREMENT      => 'Contract/Client Spec',
            self::CAREER_DEVELOPMENT      => 'Development Plan',
        };
    }
}
