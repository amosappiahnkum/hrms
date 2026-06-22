<?php

namespace App\Models\Recruitment;

use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateExperience extends ApplicationModel
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'candidate_id',
        'company_name',
        'job_title',
        'start_date',
        'end_date',
        'is_current',
        'description',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
