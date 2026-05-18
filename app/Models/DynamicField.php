<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DynamicField extends AppModel
{
    protected $fillable = [
        'uuid',
        'dynamic_form_id',
        'name',
        'label',
        'type',
        'col_span',
        'sort_order',
        'is_required',
        'is_active',
        'props',
        'rules',
        'behavior',
        'data_source',
        'transformers',
    ];

    protected $casts = [
        'props' => 'array',
        'rules' => 'array',
        'behavior' => 'array',
        'data_source' => 'array',
        'transformers' => 'array',

        'is_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'dynamic_form_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(DynamicFieldOption::class)
            ->orderBy('sort_order');
    }

    public function values(): HasMany
    {
        return $this->hasMany(
            DynamicFieldValue::class
        );
    }
}
