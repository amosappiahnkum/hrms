<?php

namespace App\Enums\Competency;

/** Ways to close a competency gap (procedure 5.3.5), each with how its effectiveness is checked (5.3.6). */
enum DevelopmentMethod: string
{
    case TRAINING = 'training';
    case ON_THE_JOB = 'on_the_job';
    case COACHING = 'coaching';
    case MENTORING = 'mentoring';
    case JOB_SHADOWING = 'job_shadowing';
    case WORKSHOP = 'workshop';
    case CERTIFICATION = 'certification';
    case ASSIGNMENT = 'assignment';
    case SELF_STUDY = 'self_study';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TRAINING      => 'Formal training',
            self::ON_THE_JOB    => 'On-the-job training',
            self::COACHING      => 'Coaching',
            self::MENTORING     => 'Mentoring',
            self::JOB_SHADOWING => 'Job shadowing',
            self::WORKSHOP      => 'Workshop or seminar',
            self::CERTIFICATION => 'Professional certification',
            self::ASSIGNMENT    => 'Cross-functional or acting assignment',
            self::SELF_STUDY    => 'Self-directed learning',
            self::OTHER         => 'Other',
        };
    }

    public function effectivenessCheck(): string
    {
        return match ($this) {
            self::TRAINING      => 'Examination results, assessments, observation of performance',
            self::ON_THE_JOB    => 'Demonstration of competence under supervision',
            self::COACHING      => 'Supervisor evaluation and performance improvement',
            self::MENTORING     => 'Achievement of agreed development objectives',
            self::JOB_SHADOWING => 'Observation and practical demonstration',
            self::WORKSHOP      => 'Application of knowledge in the workplace',
            self::CERTIFICATION => 'Certification achieved and applied in practice',
            self::ASSIGNMENT    => 'Successful completion of assigned responsibilities',
            self::SELF_STUDY    => 'Verification of competency by competent personnel',
            self::OTHER         => 'Verification of competency by competent personnel',
        };
    }
}
