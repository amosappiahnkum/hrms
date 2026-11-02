<?php

namespace App\Models\TrainingPlan;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The subject area a training covers (NDE, HSE, Rigging…), used to group and report on trainings.
 * Not the trainee's department: one department takes trainings in many domains.
 */
class TrainingDomain extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['name'];

    /** Swap a validated `domain_uuid` (null clears it) for the `training_domain_id` it names. */
    public static function resolveUuid(array $data): array
    {
        if (array_key_exists('domain_uuid', $data)) {
            $data['training_domain_id'] = $data['domain_uuid'] ? static::where('uuid', $data['domain_uuid'])->value('id') : null;
            unset($data['domain_uuid']);
        }

        return $data;
    }

    public function catalogueItems(): HasMany
    {
        return $this->hasMany(TrainingCatalogueItem::class);
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(TrainingPlanItem::class);
    }
}
