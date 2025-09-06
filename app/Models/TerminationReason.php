<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TerminationReason extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'reason', 'user_id'
    ];
}
