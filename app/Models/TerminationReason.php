<?php

namespace App\Models;

use App\Traits\RecordsActivity;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TerminationReason extends Model
{
    use HasFactory, HasUuid, RecordsActivity;

    protected $fillable = [
        'reason', 'user_id'
    ];
}
