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
    case SEMINAR = 'seminar';
    case PROFESSIONAL_MEMBERSHIP = 'professional_membership';
    case ASSIGNMENT = 'assignment';
    case TEMPORARY_ASSIGNMENT = 'temporary_assignment';
    case PROJECT = 'project';
    case OBSERVATION = 'observation';
    case SELF_STUDY = 'self_study';
    case CERTIFICATION = 'certification';
    case KNOWLEDGE_SHARING = 'knowledge_sharing';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TRAINING                => 'Formal training',
            self::ON_THE_JOB              => 'On-the-job training',
            self::COACHING                => 'Coaching',
            self::MENTORING               => 'Mentoring',
            self::JOB_SHADOWING           => 'Job shadowing',
            self::WORKSHOP                => 'Workshop',
            self::SEMINAR                 => 'Seminar or conference',
            self::PROFESSIONAL_MEMBERSHIP => 'Professional membership',
            self::ASSIGNMENT              => 'Cross-functional assignment',
            self::TEMPORARY_ASSIGNMENT    => 'Temporary role or acting appointment',
            self::PROJECT                 => 'Project or improvement initiative',
            self::OBSERVATION             => 'Observation and supervised practice',
            self::SELF_STUDY              => 'Self-directed learning',
            self::CERTIFICATION           => 'Professional certification',
            self::KNOWLEDGE_SHARING       => 'Knowledge sharing session',
            self::OTHER                   => 'Other',
        };
    }

    public function effectivenessCheck(): string
    {
        return match ($this) {
            self::TRAINING                => 'Examination results, assessments, observation of performance',
            self::ON_THE_JOB              => 'Demonstration of competence under supervision',
            self::COACHING                => 'Supervisor evaluation and performance improvement',
            self::MENTORING               => 'Achievement of agreed development objectives',
            self::JOB_SHADOWING           => 'Observation and practical demonstration',
            self::WORKSHOP, self::SEMINAR => 'Application of knowledge in the workplace',
            self::PROFESSIONAL_MEMBERSHIP => 'Maintenance of membership and application of professional knowledge',
            self::ASSIGNMENT              => 'Successful completion of assigned responsibilities',
            self::TEMPORARY_ASSIGNMENT    => 'Demonstrated ability to perform assigned duties',
            self::CERTIFICATION           => 'Certification achieved and applied in practice',
            self::PROJECT, self::OBSERVATION, self::SELF_STUDY, self::KNOWLEDGE_SHARING, self::OTHER
                                          => 'Verification of competency by competent personnel',
        };
    }
}
