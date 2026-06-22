<?php

namespace App\Models\Recruitment;

use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateQualification extends ApplicationModel
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'candidate_id',
        'institution',
        'award',
        'field_of_study',
        'start_date',
        'end_date',
        'grade',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
