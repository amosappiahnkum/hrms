<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DynamicFieldValue extends AppModel
{
    protected $fillable = [

        'uuid',

        'dynamic_field_id',

        'valuable_type',

        'valuable_id',

        'value',
    ];

    protected $casts = [

        'value' => 'array',
    ];

    public function field(): BelongsTo
    {
        return $this->belongsTo(
            DynamicField::class,
            'dynamic_field_id'
        );
    }

    public function valuable(): MorphTo
    {
        return $this->morphTo();
    }
}
