<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Back pay owed for an earlier payslip after a backdated raise, paid by a later run. */
class PayArrear extends AppModel
{
    protected $table = 'pay_arrears';

    protected $fillable = ['employee_id', 'payslip_id', 'amount', 'pay_run_id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayRun::class, 'pay_run_id');
    }
}
