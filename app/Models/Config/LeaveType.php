<?php

namespace App\Models\Config;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'description',
        'request_type',
        'requires_document',
        'max_documents',
    ];

    /**
     * @return HasMany
     */
    public function leaveTypeLevelConfigs(): HasMany
    {
        return $this->hasMany(LeaveTypeLevelConfig::class);
    }
}
