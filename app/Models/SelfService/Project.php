<?php

namespace App\Models\SelfService;

use App\Models\AppModel;
use App\Models\User;
use App\Traits\HasApprovalUpdates;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends AppModel
{
    use HasFactory, HasUuid, HasApprovalUpdates;

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

    public function approvableFields(): array
    {
        return [
            'title' => ['show_in_diff' => true],
            'end_year' => ['show_in_diff' => true],
            'start_year' => ['show_in_diff' => true],
            'location' => ['show_in_diff' => true],
            'significance' => ['show_in_diff' => true],
            'description' => ['show_in_diff' => true],
            'role' => ['show_in_diff' => true],
            'status' => ['show_in_diff' => true],
            'employee_id' => ['show_in_diff' => false],
            'user_id' => ['show_in_diff' => false],
        ];
    }

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
