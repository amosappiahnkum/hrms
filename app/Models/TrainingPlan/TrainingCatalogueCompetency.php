<?php

namespace App\Models\TrainingPlan;

use App\Models\AppModel;
use App\Models\Competency\Competency;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A competency a catalogue training develops, and the level it brings a trainee to. */
class TrainingCatalogueCompetency extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['training_catalogue_item_id', 'competency_id', 'target_level'];

    protected $casts = ['target_level' => 'integer'];

    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(TrainingCatalogueItem::class, 'training_catalogue_item_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
