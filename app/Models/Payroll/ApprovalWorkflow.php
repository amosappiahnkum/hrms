<?php

namespace App\Models\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An approval chain HR defines for a process. */
class ApprovalWorkflow extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['process', 'name', 'is_default', 'distinct_approvers'];

    protected $casts = [
        'process'            => ApprovalProcess::class,
        'is_default'         => 'boolean',
        'distinct_approvers' => 'boolean',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalWorkflowStep::class)->orderBy('position');
    }
}
