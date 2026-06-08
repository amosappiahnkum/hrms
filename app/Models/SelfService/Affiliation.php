<?php

namespace App\Models\SelfService;

use App\Models\AppModel;
use App\Models\User;
use App\Traits\HasApprovalUpdates;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Affiliation extends AppModel
{
    use HasApprovalUpdates;

    protected $fillable = [
        'association',
        'role',
        'description',
        'start',
        'end',
        'user_id',
        'employee_id',
    ];

    public function approvableFields(): array
    {
        return [
            'association' => ['show_in_diff' => true],
            'role' => ['show_in_diff' => true],
            'description' => ['show_in_diff' => true],
            'start' => ['show_in_diff' => true],
            'end' => ['show_in_diff' => true],
            'employee_id' => ['show_in_diff' => false],
            'user_id' => ['show_in_diff' => false],
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
