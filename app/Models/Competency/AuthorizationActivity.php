<?php

namespace App\Models\Competency;

use App\Models\AppModel;
use App\Models\Position;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Something people need formal authorization to do, e.g. "Sign UT inspection reports". */
class AuthorizationActivity extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'position_id', 'validity_months'];

    protected $casts = ['validity_months' => 'integer'];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(AuthorizationRequirement::class);
    }

    public function authorizations(): HasMany
    {
        return $this->hasMany(EmployeeAuthorization::class);
    }
}
