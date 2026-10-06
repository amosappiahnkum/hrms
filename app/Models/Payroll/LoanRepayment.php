<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid back on a loan: deducted by a pay run, or paid by hand (cash, transfer, settlement). */
class LoanRepayment extends AppModel
{
    protected $fillable = ['loan_id', 'loan_instalment_id', 'amount', 'paid_on', 'source', 'pay_run_id', 'reference', 'notes', 'recorded_by'];

    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date'];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
