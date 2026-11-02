<?php

namespace App\Enums\Payroll;

/** What an approval workflow is for, and which values its steps may adjust. */
enum ApprovalProcess: string
{
    case OVERTIME = 'overtime';
    case LOAN = 'loan';
    case TIME_INPUT = 'time_input';
    case PAY_RUN = 'pay_run';
    case TIMESHEET = 'timesheet';
    /** Heads above the appraiser endorsing an appraisal (steps from the setting appraisal.endorsement_chain). */
    case APPRAISAL = 'appraisal';

    public function label(): string
    {
        return match ($this) {
            self::OVERTIME => 'Overtime requests',
            self::LOAN => 'Loan requests',
            self::TIME_INPUT => 'Time inputs',
            self::PAY_RUN => 'Pay runs',
            self::TIMESHEET => 'Timesheets',
            self::APPRAISAL => 'Appraisal endorsement',
        };
    }

    /** Set up in Payroll › Approval workflows. Appraisal endorsement follows its own setting and screens instead. */
    public function configurable(): bool
    {
        return $this !== self::APPRAISAL;
    }

    /**
     * Yes/no questions a step's approver can be asked, copied onto the request when answered.
     * Overtime: the supervisor's two questions on the overtime request form (FM-07).
     *
     * @return array<string, string> key => question
     */
    public function questions(): array
    {
        return match ($this) {
            self::OVERTIME => [
                'can_postpone' => 'Can the work be postponed and completed during normal working hours?',
                'on_client_timesheet' => "Will the overtime be reflected on the client's timesheet?",
            ],
            default => [],
        };
    }

    /** @return array<string, string> field => label */
    public function adjustable(): array
    {
        return match ($this) {
            self::OVERTIME => ['approved_hours' => 'Approved hours'],
            self::LOAN => ['amount' => 'Amount', 'tenor_months' => 'Repayment period'],
            self::TIME_INPUT => ['quantity' => 'Quantity'],
            self::PAY_RUN => [],
            self::TIMESHEET => [],
            self::APPRAISAL => [],
        };
    }
}
