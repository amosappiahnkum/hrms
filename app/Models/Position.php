<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends ApplicationModel
{
    use SoftDeletes, HasUuid;

    protected $fillable = ['name'];

    /** The competency levels this position requires. */
    public function competencyRequirements(): HasMany
    {
        return $this->hasMany(\App\Models\Competency\PositionCompetency::class);
    }

    public function jobDetails(): HasMany
    {
        return $this->hasMany(JobDetail::class);
    }
}
