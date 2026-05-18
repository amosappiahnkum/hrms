<?php

namespace App\Models\SelfService;

use App\Models\AppModel;
use App\Models\User;
use App\Traits\HasDynamicFields;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GrantAndFund extends AppModel
{
    use HasFactory, HasUuid, HasDynamicFields;

    protected $fillable = [
        'source',
        'purpose',
        'amount',
        'benefactor',
        'date',
        'description',
        'start',
        'end',
        'currency',
        'employee_id',
        'user_id'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
