<?php

namespace App\Models;

use App\Traits\RecordsActivity;
use App\Models\SelfService\Employee;
use App\Models\TrainingPlan\TrainingPlanItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class EmployeeCertification extends Model
{
    use SoftDeletes, RecordsActivity;

    protected $fillable = [
        'employee_id',
        'training_plan_item_id',
        'certification_provider_id',
        'certification_type_id',
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

    public function trainingPlanItem(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanItem::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CertificationProvider::class, 'certification_provider_id');
    }

    /** The kind of certificate, when HR has set it (needed to meet a position's requirements). */
    public function type(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Competency\CertificationType::class, 'certification_type_id');
    }

    /** Valid on the given day: received, and not expired. */
    public function isValidOn(\DateTimeInterface $day): bool
    {
        return $this->does_not_expire || !$this->expiry_date || $this->expiry_date->gte(\Carbon\Carbon::instance($day)->startOfDay());
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
