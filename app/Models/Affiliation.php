<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Affiliation extends AppModel
{
    protected $fillable = [
        'association',
        'role',
        'description',
        'start',
        'end',
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
