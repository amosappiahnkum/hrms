<?php

namespace App\Models\Config;

use App\Models\AppModel;

class Setting extends AppModel
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'description',
        'is_public',
    ];

    protected $casts = [
        'value' => 'array',
        'is_public' => 'boolean',
    ];
}
