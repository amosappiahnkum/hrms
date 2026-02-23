<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveTypeLevelConfig extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'job_category_id',
        'leave_type_id',
        'entitlement_type',
        'number_of_days',
        'start_of_annual_cycle',
        'allow_half_day',
        'allow_carry_forward',
        'maximum_allotment',
        'maximum_consecutive_days',
        'should_request_before'
    ];

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function jobCategory(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class);
    }
}
