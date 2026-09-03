<?php

namespace App\Models;

use App\Models\SelfService\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class EmployeeCertification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'certification_provider_id',
        'title',
        'description',
        'date_received',
        'expiry_date',
        'does_not_expire',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    protected $casts = [
        'date_received'    => 'date',
        'expiry_date'      => 'date',
        'does_not_expire'  => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CertificationProvider::class, 'certification_provider_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->where('does_not_expire', false)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now(), now()->addDays($days)]);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('does_not_expire', false)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now());
    }
}
