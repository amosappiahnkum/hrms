<?php

namespace App\Models\Payroll;

use App\Contracts\ApprovalSubject;
use App\Exceptions\UserFacingException;
use App\Models\AppModel;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/** Units for one employee in a month (offshore days, shifts…), priced by a per-unit pay component. */
class TimeInput extends AppModel implements ApprovalSubject
{
    use SoftDeletes;

    protected $fillable = ['employee_id', 'pay_component_id', 'year', 'month', 'quantity', 'notes', 'status', 'entered_by', 'pay_run_id'];

    protected $casts = ['quantity' => 'decimal:2', 'year' => 'integer', 'month' => 'integer'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id')->withTrashed();
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    public function approval(): MorphOne
    {
        return $this->morphOne(Approval::class, 'subject')->latestOfMany();
    }

    public function periodLabel(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('F Y');
    }

    public function adjustableValues(): array
    {
        return ['quantity' => (float) $this->quantity];
    }

    public function applyAdjustments(array $values): void
    {
        if (array_key_exists('quantity', $values)) {
            $quantity = (float) $values['quantity'];
            if ($quantity <= 0 || $quantity > (float) $this->quantity) {
                throw new UserFacingException('The approved quantity must be more than 0 and no more than entered (' . (float) $this->quantity . ').');
            }
            $this->update(['quantity' => $quantity]);
        }
    }

    public function approvalFinished(Approval $approval): void
    {
        $this->update(['status' => $approval->status]);
    }

    public function approvalSummary(): string
    {
        $unit = $this->component?->unit ?: 'units';

        return (float) $this->quantity . " {$unit} of {$this->component?->name} in " . $this->periodLabel() . ($this->notes ? " ({$this->notes})" : '');
    }
}
