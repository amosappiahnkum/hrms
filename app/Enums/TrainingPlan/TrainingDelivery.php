<?php

namespace App\Enums\TrainingPlan;

enum TrainingDelivery: string
{
    case INTERNAL = 'internal';
    case EXTERNAL = 'external';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
