<?php

namespace App\Traits;

use App\Models\InformationUpdate;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasApprovalUpdates
{
    public function informationUpdates()
    {
        return $this->morphMany(InformationUpdate::class, 'information');
    }

    public function pendingApproval(): MorphOne
    {
        return $this->morphOne(InformationUpdate::class, 'information')
            ->where('status', 'pending')
            ->latestOfMany();
    }

    public function pendingUpdate()
    {
        return $this->informationUpdates()->where('status', 'pending')->latest()->first();
    }
}
