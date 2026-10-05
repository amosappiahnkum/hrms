<?php

namespace App\Models\Competency;

use App\Enums\Competency\AuthorizationStatus;
use App\Models\AppModel;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An employee's authorization for an activity: who recommended and granted it, and until when. */
class EmployeeAuthorization extends AppModel
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'authorization_activity_id', 'status',
        'recommended_by', 'recommended_at', 'recommendation_note',
        'authorized_by', 'authorized_at', 'valid_until',
        'reason', 'status_changed_by', 'status_changed_at', 'expiry_reminded_at',
    ];

    protected $casts = [
        'status'             => AuthorizationStatus::class,
        'recommended_at'     => 'datetime',
        'authorized_at'      => 'datetime',
        'valid_until'        => 'date',
        'status_changed_at'  => 'datetime',
        'expiry_reminded_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(AuthorizationActivity::class, 'authorization_activity_id');
    }

    public function recommender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommended_by');
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn('status', AuthorizationStatus::currentValues());
    }
}
