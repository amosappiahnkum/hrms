<?php

namespace App\Models\Payroll;

use App\Contracts\ApprovalSubject;
use App\Models\AppModel;
use App\Models\SelfService\Employee;
use App\Services\Payroll\TimesheetService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/** An employee's timesheet for a pay month (FM-06): filled in, submitted, approved, then it feeds pay. */
class Timesheet extends AppModel implements ApprovalSubject
{
    use SoftDeletes;

    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'employee_id', 'year', 'month', 'period_start', 'period_end', 'template', 'scope', 'client_id', 'work_order_id',
        'task_description', 'client_rep_name', 'client_signed_on', 'client_signoff_path', 'status', 'submitted_at',
        'rejected_at', 'rejection_reason', 'approved_at', 'totals', 'notes', 'created_by',
    ];

    public const STAFF = 'staff';
    public const FREELANCE = 'freelance';

    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'submitted_at' => 'datetime', 'rejected_at' => 'datetime',
        'approved_at' => 'datetime', 'totals' => 'array', 'client_signed_on' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    public function isFreelance(): bool
    {
        return $this->template === self::FREELANCE;
    }

    public function days(): HasMany
    {
        return $this->hasMany(TimesheetDay::class)->orderBy('date');
    }

    public function approval(): MorphOne
    {
        return $this->morphOne(Approval::class, 'subject')->latestOfMany();
    }

    /** Draft or sent back: it can be filled in. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::REJECTED], true);
    }

    public function label(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('F Y');
    }

    public function adjustableValues(): array
    {
        return [];
    }

    public function applyAdjustments(array $values): void
    {
    }

    public function approvalFinished(Approval $approval): void
    {
        app(TimesheetService::class)->finished($this, $approval);
    }

    public function approvalSummary(): string
    {
        $totals = $this->totals ?? [];

        $for = $this->isFreelance() ? ' · ' . collect([$this->client?->name ?? $this->workOrder?->client?->name, $this->workOrder?->number])->filter()->implode(', ') : '';

        return "Timesheet for {$this->label()}{$for} ({$this->period_start->format('j M')} – {$this->period_end->format('j M')})"
            . (isset($totals['days_worked']) ? ", {$totals['days_worked']} days worked" : '');
    }
}
