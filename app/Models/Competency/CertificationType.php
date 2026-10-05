<?php

namespace App\Models\Competency;

use App\Models\AppModel;
use App\Models\EmployeeCertification;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A kind of certificate a role can require, e.g. "ASNT UT Level 2" or "BOSIET". */
class CertificationType extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'validity_months', 'competency_id'];

    protected $casts = ['validity_months' => 'integer'];

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class);
    }

    public function positionRequirements(): HasMany
    {
        return $this->hasMany(PositionCertification::class);
    }
}
