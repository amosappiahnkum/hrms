<?php

namespace App\Models;

use App\Enums\Statuses;
use App\Traits\HasUserId;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveRequest extends Model
{
    use HasFactory, SoftDeletes, HasUuid, HasUserId;

    protected $fillable = [
        'employee_id',
        'department_id',
        'supervisor_id',
        'leave_type_id',
        'days_requested',
        'days_approved',
        'start_date',
        'end_date',
        'reason',
        'viewed',
        'status',
        'hr_status',
        'sup_approval',
        'hr_approval',
        'sup_reason',
        'hr_reason',
        'hr_id',
        'moved',
        'moved_by',
        'user_id',
    ];

    protected $casts = [
        'status' => Statuses::class,
    ];

    /**
     * @return BelongsTo
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    /**
     * @return BelongsTo
     */
    public function approvedHr(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hr_id');
    }

    /**
     * @return BelongsTo
     */
    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'moved_by');
    }

    /**
     * @return BelongsTo
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveApproval::class);
    }
}
