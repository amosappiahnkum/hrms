<?php

namespace App\Models\TrainingPlan;

use App\Enums\TrainingPlan\TrainingNature;
use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A training that can be planned (spreadsheet sheet "List of Training"). */
class TrainingCatalogueItem extends AppModel
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'nature',
        'training_domain_id',
        'default_days',
        'estimated_cost',
        'trainer',
        'location',
    ];

    protected $casts = [
        'nature'         => TrainingNature::class,
        'default_days'   => 'decimal:1',
        'estimated_cost' => 'decimal:2',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(TrainingDomain::class, 'training_domain_id');
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(TrainingPlanItem::class);
    }
}
