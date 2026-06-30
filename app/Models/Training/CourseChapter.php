<?php

namespace App\Models\Training;

use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseChapter extends ApplicationModel
{
    use HasUuid;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class, 'chapter_id')->orderBy('order');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(ChapterProgress::class, 'chapter_id');
    }

}
