<?php

namespace App\Models\Competency;

use App\Models\AppModel;
use App\Models\Position;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The level a position requires in one competency. */
class PositionCompetency extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['position_id', 'competency_id', 'required_level'];

    protected $casts = ['required_level' => 'integer'];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
