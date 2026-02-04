<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            $column = property_exists($model, 'uuidColumn')
                ? $model->uuidColumn
                : 'uuid';

            if (empty($model->{$column})) {
                $model->{$column} = (string) Str::uuid();
            }
        });
    }
}
