<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DynamicForm extends AppModel
{
    protected $fillable = [
        'uuid',
        'name',
        'model',
        'context',
        'description',
        'is_active',
        'is_extension',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(DynamicField::class)
            ->orderBy('sort_order');
    }
}
