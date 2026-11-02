<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;
use Illuminate\Support\Str;

/**
 * In-app notification row. Every table has a required `uuid` column, which Laravel's database
 * channel does not fill — without this, in-app notifications fail to save.
 */
class DatabaseNotification extends BaseDatabaseNotification
{
    protected static function booted(): void
    {
        static::creating(function (self $notification) {
            $notification->uuid ??= (string) Str::uuid();
        });
    }
}
