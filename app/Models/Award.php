<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Award extends AppModel
{
    protected $fillable = [
        'title',
        'year',
        'giving_by',
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
