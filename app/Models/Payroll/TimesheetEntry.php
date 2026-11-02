<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What was worked on a timesheet day at one site: hours (or start and end times), and the ticks that aren't worked
 * out (offshore, rope access…). An entry for overtime approved in advance is linked to its request and is
 * planned until the employee confirms it.
 */
class TimesheetEntry extends AppModel
{
    use SoftDeletes;

    protected $fillable = [
        'timesheet_day_id', 'work_site_id', 'hours', 'start_time', 'end_time', 'break_minutes', 'values',
        'overtime_request_id', 'planned', 'notes', 'sort',
    ];

    protected $casts = ['hours' => 'float', 'values' => 'array', 'planned' => 'boolean', 'break_minutes' => 'integer', 'sort' => 'integer'];

    public function day(): BelongsTo
    {
        return $this->belongsTo(TimesheetDay::class, 'timesheet_day_id');
    }

    public function workSite(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class)->withTrashed();
    }

    public function overtimeRequest(): BelongsTo
    {
        return $this->belongsTo(OvertimeRequest::class);
    }

    /** "08:00:00" → "08:00". */
    public function startsAt(): ?string
    {
        return $this->start_time ? substr($this->start_time, 0, 5) : null;
    }

    public function endsAt(): ?string
    {
        return $this->end_time ? substr($this->end_time, 0, 5) : null;
    }
}
