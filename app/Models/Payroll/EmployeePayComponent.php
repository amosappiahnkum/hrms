<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use App\Models\SelfService\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A recurring pay component given to an employee, at the component's rate or an amount of their own. */
class EmployeePayComponent extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['employee_id', 'pay_component_id', 'amount', 'effective_from', 'effective_to', 'notes'];

    protected $casts = ['amount' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function scopeActiveOn(Builder $query, \DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
