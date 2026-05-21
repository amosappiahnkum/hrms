<?php

namespace App\Models\Recruitment;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateDocument extends Model
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

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
