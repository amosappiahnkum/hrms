<?php

namespace App\Enums\TrainingPlan;

enum PersonnelCategory: string
{
    case NON_TECHNICAL = 'non_technical';
    case SUPERVISOR_MANAGER_LEAD = 'supervisor_manager_lead';
    case TECHNICIAN_TECHNICAL = 'technician_technical';

    public function label(): string
    {
        return match ($this) {
            self::NON_TECHNICAL           => 'Non-Technical',
            self::SUPERVISOR_MANAGER_LEAD => 'Supervisor/Manager/Lead',
            self::TECHNICIAN_TECHNICAL    => 'Technician/Technical',
        };
    }
}
