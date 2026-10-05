<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalDecision extends AppModel
{
    protected $fillable = ['approval_id', 'position', 'step_name', 'decision', 'decided_by', 'comment', 'adjustments'];

    protected $casts = ['adjustments' => 'array'];

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
