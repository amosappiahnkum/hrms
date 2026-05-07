<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AppModel extends Model
{
    use HasUuid;

    protected $fillable = [
        'termination_reason_id',
        'termination_date',
        'terminated_by',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted()
    {
        static::creating(static function ($model) {

            if (
                in_array('user_id', $model->getFillable(), true)
                && empty($model->user_id)
            ) {
                $model->user_id = Auth::id();
            }

        });
    }
}
