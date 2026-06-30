<?php

namespace App\Models\Training;

use App\Models\ApplicationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChapterProgress extends ApplicationModel
{
    protected $fillable = [
        'enrollment_id',
        'chapter_id',
        'completed_at',
        'quiz_attempt_id',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'enrollment_id');
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(CourseChapter::class, 'chapter_id');
    }
}
