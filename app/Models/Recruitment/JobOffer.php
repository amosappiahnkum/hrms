<?php

namespace App\Models\Recruitment;

use App\Enums\OfferStatus;
use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobOffer extends ApplicationModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'application_id',
        'salary',
        'start_date',
        'expires_at',
        'status',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'status'     => OfferStatus::class,
        'start_date' => 'date',
        'expires_at' => 'date',
        'salary'     => 'decimal:2',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
