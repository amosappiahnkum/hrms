<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends ApplicationModel
{
    use SoftDeletes, HasUuid;

    protected $fillable = ['name'];

    public function jobDetails(): HasMany
    {
        return $this->hasMany(JobDetail::class);
    }
}
