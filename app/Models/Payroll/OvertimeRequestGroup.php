<?php

namespace App\Models\Payroll;

use App\Contracts\ApprovalSubject;
use App\Models\AppModel;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Overtime on several dates, requested at once with the same details (e.g. every Saturday of the month on one PO):
 * one approval for all of them. Each date is its own OvertimeRequest (its type, limits, timesheet and pay work per
 * day); the approver can lower a date's hours or leave a date out (0).
 */
class OvertimeRequestGroup extends AppModel implements ApprovalSubject
{
    use SoftDeletes;

    protected $fillable = ['employee_id', 'status', 'requested_by'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class)->orderBy('work_date');
    }

    public function approval(): MorphOne
    {
        return $this->morphOne(Approval::class, 'subject')->latestOfMany();
    }

    /** The approver may change each date's hours: request uuid => hours (0 leaves the date out). */
    public function adjustableValues(): array
    {
        return ['approved_hours' => $this->requests()->with('type')->get()->mapWithKeys(fn (OvertimeRequest $r) => [$r->uuid => [
            'date' => $r->work_date->toDateString(), 'type' => $r->type?->name, 'requested' => (float) $r->hours_requested, 'hours' => (float) $r->approved_hours,
        ]])->all()];
    }

    /** Each date's approved hours, up to what was asked; 0 leaves it out (at least one date must stay). */
    public function applyAdjustments(array $values): void
    {
        if (!array_key_exists('approved_hours', $values)) {
            return;
        }
        $given = collect((array) $values['approved_hours'])->map(fn ($v) => is_array($v) ? ($v['hours'] ?? null) : $v);
        $requests = $this->requests()->get()->keyBy('uuid');
        foreach ($given as $uuid => $hours) {
            $request = $requests->get($uuid);
            if (!$request || !is_numeric($hours) || $hours < 0 || $hours > (float) $request->hours_requested) {
                throw new \App\Exceptions\UserFacingException('Each date\'s approved hours must be 0 to the hours asked' . ($request ? " ({$request->work_date->format('j M')}: " . (float) $request->hours_requested . ' h).' : '.'));
            }
        }
        if ($given->isNotEmpty() && $requests->keys()->every(fn ($uuid) => (float) ($given[$uuid] ?? $requests[$uuid]->approved_hours) <= 0)) {
            throw new \App\Exceptions\UserFacingException('Leave at least one date in, or reject the request.');
        }
        foreach ($given as $uuid => $hours) {
            $requests[$uuid]->update(['approved_hours' => round((float) $hours, 2)]);
        }
    }

    /** The supervisor's FM-07 answers apply to every date. */
    public function applyAnswers(array $answers): void
    {
        $this->requests()->get()->each->applyAnswers($answers);
    }

    /**
     * Approved: each date with hours left is approved (and shows on the timesheet to confirm); dates set to 0 are
     * left out (rejected). Rejected or cancelled: every date.
     */
    public function approvalFinished(Approval $approval): void
    {
        $this->update(['status' => $approval->status]);
        foreach ($this->requests()->get() as $request) {
            $status = $approval->status === Approval::APPROVED && (float) $request->approved_hours <= 0 ? Approval::REJECTED : $approval->status;
            $request->update(['status' => $status]);
            if ($status === Approval::APPROVED) {
                app(\App\Services\Payroll\TimesheetService::class)->planOvertime($request->fresh());
            }
        }
        $this->employee?->userAccount?->notify(new \App\Notifications\ApprovalDecidedNotification($approval, '/self-service/overtime'));
    }

    public function approvalSummary(): string
    {
        $requests = $this->requests()->with('type', 'workOrder')->get();
        $first = $requests->first();
        $range = $first ? $first->work_date->format('j M') . ($requests->count() > 1 ? ' – ' . $requests->last()->work_date->format('j M Y') : $first->work_date->format(' Y')) : '';
        $what = $first?->workOrder?->number ?? ($first?->activity_description ? str($first->activity_description)->limit(60) : null);

        return "{$requests->count()} days of overtime, {$range} (" . (float) $requests->sum('approved_hours') . ' h)' . ($what ? " · {$what}" : '');
    }
}
