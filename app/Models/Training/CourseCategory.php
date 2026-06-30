<?php

namespace App\Models\Training;

use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseCategory extends ApplicationModel
{
    use HasUuid, SoftDeletes;

    protected $fillable = ['name', 'description', 'order'];

    protected $casts = ['order' => 'integer'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
