<?php

namespace App\Models\SelfService;

use App\Models\AppModel;
use App\Models\User;
use App\Traits\HasApprovalUpdates;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Achievement extends AppModel
{
    use HasApprovalUpdates;

    protected $fillable = [
        'title',
        'year',
        'description',
        'user_id',
        'employee_id',
    ];

    public function approvableFields(): array
    {
        return [
            'title' => ['show_in_diff' => true],
            'year' => ['show_in_diff' => true],
            'description' => ['show_in_diff' => true],
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
