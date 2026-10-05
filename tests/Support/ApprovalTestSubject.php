<?php

namespace Tests\Support;

use App\Contracts\ApprovalSubject;
use App\Models\Payroll\Approval;
use Illuminate\Database\Eloquent\Model;

/** A stand-in request for testing approval workflows before real subjects (overtime, loans) exist. */
class ApprovalTestSubject extends Model implements ApprovalSubject
{
    protected $table = 'approval_test_subjects';

    protected $guarded = [];

    public function adjustableValues(): array
    {
        return ['approved_hours' => $this->approved_hours];
    }

    public function applyAdjustments(array $values): void
    {
        $this->update($values);
    }

    public function approvalFinished(Approval $approval): void
    {
        $this->update(['outcome' => $approval->status]);
    }

    public function approvalSummary(): string
    {
        return "{$this->approved_hours} hours of overtime";
    }
}
