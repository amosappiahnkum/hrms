<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A client the organization works for (named on freelance timesheets and overtime requests). */
class Client extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['name', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
