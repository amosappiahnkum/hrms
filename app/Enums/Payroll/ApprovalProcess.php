<?php

namespace App\Enums\Payroll;

/** What an approval workflow is for, and which values its steps may adjust. */
enum ApprovalProcess: string
{
    case OVERTIME = 'overtime';
    case LOAN = 'loan';
    case TIME_INPUT = 'time_input';
    case PAY_RUN = 'pay_run';

    public function label(): string
    {
        return match ($this) {
            self::OVERTIME   => 'Overtime requests',
            self::LOAN       => 'Loan requests',
            self::TIME_INPUT => 'Time inputs',
            self::PAY_RUN    => 'Pay runs',
        };
    }

    /** @return array<string, string> field => label */
    public function adjustable(): array
    {
        return match ($this) {
            self::OVERTIME   => ['approved_hours' => 'Approved hours'],
            self::LOAN       => ['amount' => 'Amount', 'tenor_months' => 'Repayment period'],
            self::TIME_INPUT => ['quantity' => 'Quantity'],
            self::PAY_RUN    => [],
        };
    }
}
