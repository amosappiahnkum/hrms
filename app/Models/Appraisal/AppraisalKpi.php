<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppraisalKpi extends ApplicationModel
{
    use HasUuid;

    protected $table = 'appraisal_kpis';

    protected $fillable = [
        'assessment_attempt_id',
        'description',
        'target',
        'actual',
        'order',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'assessment_attempt_id');
    }
}
