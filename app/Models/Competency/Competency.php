<?php

namespace App\Models\Competency;

use App\Enums\Competency\CompetencyGroup;
use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Something an employee can be competent in, e.g. "Hazard identification and risk assessment". */
class Competency extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['name', 'group', 'description'];

    protected $casts = ['group' => CompetencyGroup::class];

    public function requirements(): HasMany
    {
        return $this->hasMany(PositionCompetency::class);
    }
}
