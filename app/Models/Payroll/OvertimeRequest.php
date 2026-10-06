<?php

namespace App\Models\Payroll;

use App\Contracts\ApprovalSubject;
use App\Models\AppModel;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Hours of overtime on a day, going through the overtime workflow and, once approved, into a pay run. */
class OvertimeRequest extends AppModel implements ApprovalSubject
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'overtime_type_id', 'work_date', 'hours_requested', 'approved_hours', 'location', 'reason',
        'status', 'requested_by', 'pay_run_id',
    ];

    protected $casts = ['work_date' => 'date', 'hours_requested' => 'decimal:2', 'approved_hours' => 'decimal:2'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(OvertimeType::class, 'overtime_type_id')->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    public function approval(): MorphOne
    {
        return $this->morphOne(Approval::class, 'subject')->latestOfMany();
    }

    public function adjustableValues(): array
    {
        return ['approved_hours' => (float) $this->approved_hours];
    }

    public function applyAdjustments(array $values): void
    {
        if (array_key_exists('approved_hours', $values)) {
            $hours = (float) $values['approved_hours'];
            if ($hours <= 0 || $hours > (float) $this->hours_requested) {
                throw new \App\Exceptions\UserFacingException('Approved hours must be more than 0 and no more than the hours requested (' . (float) $this->hours_requested . ').');
            }
            $this->update(['approved_hours' => $hours]);
        }
    }

    public function approvalFinished(Approval $approval): void
    {
        $this->update(['status' => $approval->status]);
        $this->employee?->userAccount?->notify(new \App\Notifications\ApprovalDecidedNotification($approval, '/self-service/overtime'));
    }

    public function approvalSummary(): string
    {
        return (float) $this->approved_hours . " h of {$this->type?->name} on " . $this->work_date->format('D j M Y') . ($this->reason ? " ({$this->reason})" : '');
    }
}
