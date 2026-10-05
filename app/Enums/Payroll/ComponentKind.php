<?php

namespace App\Enums\Payroll;

enum ComponentKind: string
{
    case EARNING = 'earning';
    case DEDUCTION = 'deduction';
    case EMPLOYER_CONTRIBUTION = 'employer_contribution';

    public function label(): string
    {
        return match ($this) {
            self::EARNING               => 'Earning',
            self::DEDUCTION             => 'Deduction',
            self::EMPLOYER_CONTRIBUTION => 'Employer contribution',
        };
    }
}
