<?php

namespace App\Models;

use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class ApplicationModel extends Model
{
    use RecordsActivity;

    protected static function booted()
    {
        static::creating(static function ($model) {
            if (Schema::hasColumn($model->getTable(), 'user_id') && empty($model->user_id)) {
                $model->user_id = Auth::id();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
