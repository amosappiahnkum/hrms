<?php

namespace App\Models\Config;

use App\Traits\RecordsActivity;
use App\Models\JobCategory;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveTypeLevelConfig extends Model
{
    use HasUuid, RecordsActivity;

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
        'should_request_before',
        'max_carry_forward_days',
    ];

    protected $casts = [
        'number_of_days' => 'float',
        'allow_half_day' => 'boolean',
        'allow_carry_forward' => 'boolean',
        'maximum_consecutive_days' => 'integer',
        'should_request_before' => 'integer',
        'max_carry_forward_days' => 'integer',
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
