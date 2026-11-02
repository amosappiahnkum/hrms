<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use App\Models\SelfService\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A variable item for one employee in a pay run: hours, units, or a one-off amount. */
class PayRunInput extends AppModel
{
    protected $fillable = ['pay_run_id', 'employee_id', 'pay_component_id', 'quantity', 'amount', 'source', 'notes', 'created_by'];

    protected $casts = ['quantity' => 'decimal:2', 'amount' => 'decimal:2'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayRun::class, 'pay_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
