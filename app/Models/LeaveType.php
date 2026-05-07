<?php

namespace App\Models;

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
    ];

    /**
     * @return HasMany
     */
    public function leaveTypeLevelConfigs(): HasMany
    {
        return $this->hasMany(LeaveTypeLevelConfig::class);
    }
}
