<?php

namespace App\Models\SelfService;

use App\Traits\HasApprovalUpdates;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunityService extends Model
{
    use HasFactory, SoftDeletes, HasUuid, HasApprovalUpdates;

    protected $fillable = [
        'start_date',
        'end_date',
        'description',
        'employee_id',
        'users_id'
    ];
}
