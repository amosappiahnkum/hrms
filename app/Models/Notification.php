<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasUuids;
    protected $primaryKey = "id";


    protected $fillable = [
        'model_id',
        'model_type',
        'read_at'
    ];

    public function model()
    {
        return $this->morphTo();
    }

    protected  $casts = [
        'data' => 'json'
    ];
}
