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
        'employee_id', 'overtime_request_group_id', 'overtime_type_id', 'addon_type_id', 'work_date', 'hours_requested', 'approved_hours', 'location', 'work_site_id',
        'work_order_id', 'activity_description', 'reason', 'can_postpone', 'on_client_timesheet', 'actual_hours',
        'requested_in_advance', 'status', 'requested_by', 'pay_run_id', 'timesheet_id',
    ];

    protected $casts = [
        'work_date'            => 'date',
        'hours_requested'      => 'decimal:2',
        'approved_hours'       => 'decimal:2',
        'actual_hours'         => 'decimal:2',
        'can_postpone'         => 'boolean',
        'on_client_timesheet'  => 'boolean',
        'requested_in_advance' => 'boolean',
    ];

    /** Pay basis (setting `payroll.overtime_pay_basis`) for overtime approved in advance and then confirmed. */
    public const PAY_BASES = ['approved' => 'The approved hours', 'actual' => 'The hours worked', 'lower' => 'Whichever is lower'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(OvertimeType::class, 'overtime_type_id')->withTrashed();
    }

    /** Requested with other dates (approved together); its approval is the group's. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OvertimeRequestGroup::class, 'overtime_request_group_id');
    }

    /** An any-day type paid on top for the same hours (e.g. offshore). */
    public function addonType(): BelongsTo
    {
        return $this->belongsTo(OvertimeType::class, 'addon_type_id')->withTrashed();
    }

    /** "Weekend", or "Weekend + Offshore". */
    public function typeLabel(): ?string
    {
        return $this->type ? $this->type->name . ($this->addonType ? " + {$this->addonType->name}" : '') : null;
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function workSite(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class)->withTrashed();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    /**
     * Hours to pay, or null while waiting: overtime approved in advance (when the organization confirms the
     * hours worked) waits for them, then pays by the pay basis.
     */
    public function payableHours(): ?float
    {
        $approved = (float) $this->approved_hours;
        if ($this->actual_hours === null) {
            return $this->requested_in_advance && setting('payroll.overtime_mode', 'after') === 'both' ? null : $approved;
        }
        $actual = (float) $this->actual_hours;

        return match (setting('payroll.overtime_pay_basis', 'lower')) {
            'approved' => $approved,
            'actual'   => $actual,
            default    => min($approved, $actual),
        };
    }

    /** The supervisor's answers (FM-07), copied from their approval step. */
    public function applyAnswers(array $answers): void
    {
        $this->update(collect($answers)->only(['can_postpone', 'on_client_timesheet'])->all());
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
        // Approved in advance: it shows up on the employee's timesheet, ready to confirm.
        if ($approval->status === Approval::APPROVED) {
            app(\App\Services\Payroll\TimesheetService::class)->planOvertime($this->fresh());
        }
        $this->employee?->userAccount?->notify(new \App\Notifications\ApprovalDecidedNotification($approval, '/self-service/overtime'));
    }

    public function approvalSummary(): string
    {
        return (float) $this->approved_hours . " h of {$this->typeLabel()} on " . $this->work_date->format('D j M Y') . ($this->reason ? " ({$this->reason})" : '');
    }
}
