<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppraisalKpi extends ApplicationModel
{
    use HasUuid;

    const string PERIOD_CURRENT = 'current';
    const string PERIOD_NEXT    = 'next';

    protected $table = 'appraisal_kpis';

    protected $fillable = [
        'assessment_attempt_id',
        'period',
        'description',
        'target',
        'actual',
        'order',
    ];

    protected $casts = [
        'target' => 'decimal:2',
        'actual' => 'decimal:2',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'assessment_attempt_id');
    }
}
