<?php

namespace App\Contracts;

use App\Models\Payroll\Approval;

/** Something that goes through an approval workflow (an overtime request, a loan…). */
interface ApprovalSubject
{
    /** Current values of the fields approvers may adjust, e.g. ['approved_hours' => 6]. */
    public function adjustableValues(): array;

    /** Apply an approver's adjustments (already checked against the step's allowed fields). */
    public function applyAdjustments(array $values): void;

    /** The workflow finished: approved or rejected. */
    public function approvalFinished(Approval $approval): void;

    /** A short description for notifications, e.g. "6 hours of overtime on 3 Oct". */
    public function approvalSummary(): string;
}
