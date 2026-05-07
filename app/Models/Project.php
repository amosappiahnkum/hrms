<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends AppModel
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'title',
        'end_year',
        'start_year',
        'location',
        'significance',
        'description',
        'user_id',
        'role',
        'status',
        'collaborators',
        'employee_id'
    ];

    protected $casts = [
        'collaborators' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
