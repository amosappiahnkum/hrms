<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Models\EmployeeCertification;
use App\Models\Training\CourseEnrollment;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppraisalTraining extends ApplicationModel
{
    use HasUuid;

    const array TYPES = ['seminar', 'workshop', 'course', 'conference', 'certification', 'other'];

    const string SOURCE_INTERNAL      = 'internal';
    const string SOURCE_CERTIFICATION = 'certification';
    const string SOURCE_MANUAL        = 'manual';

    protected $table = 'appraisal_trainings';

    protected $fillable = [
        'uuid',
        'assessment_attempt_id',
        'subject',
        'type',
        'start_date',
        'end_date',
        'source',
        'course_enrollment_id',
        'employee_certification_id',
        'order',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'assessment_attempt_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'course_enrollment_id');
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(EmployeeCertification::class, 'employee_certification_id');
    }
}
