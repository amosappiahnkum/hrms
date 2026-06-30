<?php

namespace App\Models\Training;

use App\Models\ApplicationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialView extends ApplicationModel
{
    public $timestamps = false;

    protected $fillable = [
        'enrollment_id',
        'material_id',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'enrollment_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class, 'material_id');
    }
}
