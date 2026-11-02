<?php

namespace App\Models\Payroll;

use App\Contracts\ApprovalSubject;
use App\Models\AppModel;
use App\Models\SelfService\Employee;
use App\Notifications\ApprovalDecidedNotification;
use App\Services\Payroll\LoanService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A staff loan: requested, approved (amount and tenor adjustable), paid out, repaid by instalments. */
class Loan extends AppModel implements ApprovalSubject
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'loan_type_id', 'amount_requested', 'amount', 'tenor_months', 'purpose', 'guarantor_id',
        'interest_method', 'interest_rate', 'status', 'disbursed_on', 'disbursement_method', 'disbursement_reference',
        'disbursement_run_id', 'total_repayable', 'settled_on', 'requested_by', 'disbursed_by',
    ];

    protected $casts = [
        'amount_requested' => 'decimal:2',
        'amount'           => 'decimal:2',
        'interest_rate'    => 'decimal:3',
        'total_repayable'  => 'decimal:2',
        'tenor_months'     => 'integer',
        'disbursed_on'     => 'date',
        'settled_on'       => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'guarantor_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LoanType::class, 'loan_type_id')->withTrashed();
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(LoanInstalment::class)->orderBy('number');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function approval(): MorphOne
    {
        return $this->morphOne(Approval::class, 'subject')->latestOfMany();
    }

    /** What is still owed: the instalments still due (paused ones were moved to the end). */
    public function balance(): float
    {
        return round((float) $this->instalments()->where('status', 'due')->sum('amount'), 2);
    }

    public function adjustableValues(): array
    {
        return ['amount' => (float) $this->amount, 'tenor_months' => $this->tenor_months];
    }

    public function applyAdjustments(array $values): void
    {
        app(LoanService::class)->adjust($this, $values);
    }

    public function approvalFinished(Approval $approval): void
    {
        $this->update(['status' => $approval->status]);
        $this->employee?->userAccount?->notify(new ApprovalDecidedNotification($approval, '/self-service/loans'));
    }

    public function approvalSummary(): string
    {
        $money = number_format((float) $this->amount, 2) . ' ' . setting('payroll.base_currency', 'GHS');

        return "{$this->type?->name} of {$money} over {$this->tenor_months} month" . ($this->tenor_months === 1 ? '' : 's') . ($this->purpose ? " ({$this->purpose})" : '');
    }
}
