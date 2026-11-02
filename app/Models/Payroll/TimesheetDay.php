<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day on a timesheet: its values by column (on staff sheets, worked out from its entries), the site worked,
 * and whether it was leave.
 */
class TimesheetDay extends Model
{
    protected $fillable = ['timesheet_id', 'date', 'values', 'work_site_id', 'is_leave', 'leave_request_id', 'notes'];

    protected $casts = ['date' => 'date', 'values' => 'array', 'is_leave' => 'boolean'];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function workSite(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class)->withTrashed();
    }

    /** What was worked, per site (staff sheets). */
    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class)->orderBy('sort')->orderBy('id');
    }
}
