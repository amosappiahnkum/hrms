<?php

namespace App\Models\Competency;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One condition for an authorization: a competency at a minimum level, or a valid certificate of a type. */
class AuthorizationRequirement extends AppModel
{
    use SoftDeletes;

    protected $table = 'authorization_activity_requirements';

    protected $fillable = ['authorization_activity_id', 'competency_id', 'min_level', 'certification_type_id'];

    protected $casts = ['min_level' => 'integer'];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(AuthorizationActivity::class, 'authorization_activity_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function certificationType(): BelongsTo
    {
        return $this->belongsTo(CertificationType::class);
    }
}
