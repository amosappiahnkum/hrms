<?php

namespace App\Models;

use App\Enums\Statuses;
use App\Models\Config\LeaveType;
use App\Models\SelfService\Employee;
use App\Traits\HasUserId;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\LeaveRequestDocument;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveRequest extends AppModel
{
    use HasFactory, SoftDeletes, HasUserId;

    protected $fillable = [
        'employee_id',
        'reliever_id',
        'department_id',
        'supervisor_id',
        // The heads who approve, in order (employee ids), and whose turn it is (an index); supervisor_id is theirs.
        'approval_chain',
        'approval_step',
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
        'approval_chain' => 'array',
        'approval_step' => 'integer',
    ];

    /** The head after the current one, if the chain goes on. */
    public function nextApproverId(): ?int
    {
        $chain = $this->approval_chain ?? [];
        $next = ($this->approval_step ?? 0) + 1;

        return isset($chain[$next]) ? (int) $chain[$next] : null;
    }

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

    public function reliever(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reliever_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveApproval::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LeaveRequestDocument::class);
    }

    public function resumption(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LeaveResumption::class);
    }

    public function scopeForDepartment($query, ?int $departmentId)
    {
        return $query->when($departmentId, function ($q) use ($departmentId) {
            // The department and its sub-departments.
            $q->whereHas('employee', fn ($e) => $e->whereIn('department_id', \App\Models\Config\Department::subtreeIds($departmentId)));
        });
    }

    public function scopeSearchEmployee($query, string $search)
    {
        return $query->whereHas('employee', function ($q) use ($search) {
            $q->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        });
    }
}
