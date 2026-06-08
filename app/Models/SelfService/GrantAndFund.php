<?php

namespace App\Models\SelfService;

use App\Models\AppModel;
use App\Models\User;
use App\Traits\HasApprovalUpdates;
use App\Traits\HasDynamicFields;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GrantAndFund extends AppModel
{
    use HasFactory, HasUuid, HasDynamicFields, HasApprovalUpdates;

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

    public function approvableFields(): array
    {
        return [
            'source' => ['show_in_diff' => true],
            'purpose' => ['show_in_diff' => true],
            'amount' => ['show_in_diff' => true],
            'benefactor' => ['show_in_diff' => true],
            'date' => ['show_in_diff' => true],
            'description' => ['show_in_diff' => true],
            'start' => ['show_in_diff' => true],
            'end' => ['show_in_diff' => true],
            'currency' => ['show_in_diff' => true],
            'employee_id' => ['show_in_diff' => false],
            'user_id' => ['show_in_diff' => false],
        ];
    }

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
