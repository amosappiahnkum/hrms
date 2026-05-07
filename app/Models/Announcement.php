<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Announcement extends ApplicationModel
{
    use HasFactory, HasUuid;
}
