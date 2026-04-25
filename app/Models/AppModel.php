<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
            if (isset($model->user_id)) {
                $model->user_id = Auth::id();
            }
        });
    }
}
