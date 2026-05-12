<?php

namespace App\Traits;

use App\Models\DynamicFieldValue;

trait HasDynamicFields
{
    public function dynamicValues()
    {
        return $this->morphMany(DynamicFieldValue::class, 'valuable');
    }
}
