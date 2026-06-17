<?php

namespace App\Models;

use App\Models\SelfService\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveResumption extends AppModel
{
    protected $fillable = [
        'leave_request_id',
        'employee_confirmed_at',
        'hod_id',
        'hod_acknowledged_at',
        'status',
    ];

    protected $casts = [
        'employee_confirmed_at' => 'datetime',
        'hod_acknowledged_at'   => 'datetime',
    ];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function hod(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hod_id');
    }
}
