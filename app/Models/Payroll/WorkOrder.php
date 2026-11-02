<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A PO, work order or project number, usually for a client. */
class WorkOrder extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'number', 'description', 'start_date', 'end_date', 'active'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'active' => 'boolean'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
