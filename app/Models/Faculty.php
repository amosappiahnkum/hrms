<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Faculty extends AppModel
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'uuid',
        'description',
    ];
}
