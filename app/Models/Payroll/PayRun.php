<?php

namespace App\Models\Payroll;

use App\Contracts\ApprovalSubject;
use App\Enums\Payroll\PayRunStatus;
use App\Models\AppModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One month's payroll (or an off-cycle run): its payslips, the rates it used, and its approval. */
class PayRun extends AppModel implements ApprovalSubject
{
    use SoftDeletes;

    protected $fillable = [
        'year', 'month', 'type', 'name', 'period_start', 'period_end', 'pay_date', 'status', 'statutory_rate_set_id',
        'exchange_rates', 'settings', 'totals', 'notes', 'prepared_by', 'calculated_at', 'approved_at', 'paid_at', 'paid_by',
    ];

    protected $casts = [
        'status'         => PayRunStatus::class,
        'period_start'   => 'date',
        'period_end'     => 'date',
        'pay_date'       => 'date',
        'exchange_rates' => 'array',
        'settings'       => 'array',
        'totals'         => 'array',
        'calculated_at'  => 'datetime',
        'approved_at'    => 'datetime',
        'paid_at'        => 'datetime',
    ];

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function rateSet(): BelongsTo
    {
        return $this->belongsTo(StatutoryRateSet::class, 'statutory_rate_set_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** The latest approval request (a run can be sent again after a rejection). */
    public function approval(): MorphOne
    {
        return $this->morphOne(Approval::class, 'subject')->latestOfMany();
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
        $this->update($approval->status === Approval::APPROVED
            ? ['status' => PayRunStatus::APPROVED, 'approved_at' => now()]
            : ['status' => PayRunStatus::CALCULATED]);
    }

    public function approvalSummary(): string
    {
        $net = number_format((float) ($this->totals['net_pay'] ?? 0), 2);

        return "{$this->name}: {$this->totals['employees']} employees, net pay {$net}";
    }
}
