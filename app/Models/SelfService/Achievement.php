<?php

namespace App\Models\SelfService;

use App\Models\AppModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Achievement extends AppModel
{
    protected $fillable = [
        'title',
        'year',
        'description',
        'user_id',
        'employee_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
