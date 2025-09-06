<?php

namespace App\Models;

use App\Enums\Statuses;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InformationUpdate extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'information_id',
        'information_type',
        'old_info',
        'new_info',
        'status',
        'status_changed_date',
        'status_changed_by',
        'requested_by'
    ];

    protected $casts = [
        'status' => Statuses::class,
        'old_info' => 'array',
        'new_info' => 'array',
    ];

    public function information(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
