<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DynamicFieldOption extends AppModel
{
    protected $fillable = [
        'uuid',
        'dynamic_field_id',
        'label',
        'value',
        'sort_order',
    ];

    public function field(): BelongsTo
    {
        return $this->belongsTo(DynamicField::class);
    }
}
