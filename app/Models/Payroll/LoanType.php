<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A kind of staff loan or advance the organization offers, with its limits and interest. */
class LoanType extends AppModel
{
    use SoftDeletes;

    public const INTEREST_METHODS = ['none' => 'No interest', 'flat' => 'Flat rate', 'reducing_balance' => 'Reducing balance'];

    protected $fillable = [
        'name', 'description', 'interest_method', 'interest_rate', 'max_amount', 'max_times_basic', 'max_tenor_months',
        'min_service_months', 'max_active_loans', 'max_deduction_percent', 'requires_guarantor', 'approval_workflow_id', 'active',
    ];

    protected $casts = [
        'interest_rate'         => 'decimal:3',
        'max_amount'            => 'decimal:2',
        'max_times_basic'       => 'decimal:2',
        'max_tenor_months'      => 'integer',
        'min_service_months'    => 'integer',
        'max_active_loans'      => 'integer',
        'max_deduction_percent' => 'decimal:2',
        'requires_guarantor'    => 'boolean',
        'active'                => 'boolean',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
