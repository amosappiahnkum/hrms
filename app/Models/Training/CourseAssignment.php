<?php

namespace App\Models\Training;

use App\Models\ApplicationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseAssignment extends ApplicationModel
{
    protected $fillable = [
        'course_id',
        'scope_type',
        'scope_ids',
        'due_date',
    ];

    protected $casts = [
        'scope_ids' => 'array',
        'due_date'  => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
