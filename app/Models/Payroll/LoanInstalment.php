<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** One month's repayment of a loan: due, paid (by payroll or by hand), or paused to the end. */
class LoanInstalment extends AppModel
{
    protected $fillable = ['loan_id', 'number', 'year', 'month', 'principal', 'interest', 'amount', 'status', 'pay_run_id'];

    protected $casts = ['principal' => 'decimal:2', 'interest' => 'decimal:2', 'amount' => 'decimal:2', 'number' => 'integer', 'year' => 'integer', 'month' => 'integer'];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    public function period(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1);
    }
}
