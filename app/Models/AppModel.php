<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AppModel extends Model
{
    use HasUuid;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted()
    {
        static::creating(static function ($model) {
            $model->user_id = Auth::id();
        });
    }
}
