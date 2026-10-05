<?php

namespace App\Models\Competency;

use App\Models\AppModel;
use App\Models\Position;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A certificate a position requires (mandatory) or recommends. */
class PositionCertification extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['position_id', 'certification_type_id', 'mandatory'];

    protected $casts = ['mandatory' => 'boolean'];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CertificationType::class, 'certification_type_id');
    }
}
