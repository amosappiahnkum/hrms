<?php

namespace App\Traits;

trait HasUserId
{
    protected static function bootHasUserId(): void
    {
        static::creating(function ($model) {
            $column = property_exists($model, 'userIdColumn')
                ? $model->userIdColumn
                : 'user_id';

            if (auth()->check() && empty($model->{$column})) {
                $model->{$column} = auth()->id();
            }
        });
    }
}
