<?php

namespace App\Models\Recruitment;

use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateDocument extends ApplicationModel
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'candidate_id',
        'type',
        'display_name',
        'path',
        'mime_type',
        'size',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
