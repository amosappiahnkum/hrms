<?php

namespace App\Models\Payroll;

use App\Enums\Payroll\ApproverType;
use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalWorkflowStep extends AppModel
{
    protected $fillable = ['approval_workflow_id', 'position', 'name', 'approver_type', 'approver_value', 'can_adjust'];

    protected $casts = [
        'approver_type'  => ApproverType::class,
        'approver_value' => 'array',
        'can_adjust'     => 'array',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }
}
