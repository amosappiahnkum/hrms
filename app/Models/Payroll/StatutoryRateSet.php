<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** SSNIT, PAYE and related rates in force from a date. */
class StatutoryRateSet extends AppModel
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'effective_from', 'notes', 'ssnit', 'paye_bands', 'reliefs', 'tier3_relief_limit_percent',
        'overtime_tax', 'bonus_tax', 'confirmed_at', 'confirmed_by', 'created_by',
    ];

    protected $casts = [
        'effective_from'             => 'date',
        'ssnit'                      => 'array',
        'paye_bands'                 => 'array',
        'reliefs'                    => 'array',
        'overtime_tax'               => 'array',
        'bonus_tax'                  => 'array',
        'tier3_relief_limit_percent' => 'decimal:2',
        'confirmed_at'               => 'datetime',
    ];

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** The set in force on a date (confirmed or not; callers decide whether that matters). */
    public static function inForceOn(\DateTimeInterface|string $date): ?self
    {
        return static::whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
