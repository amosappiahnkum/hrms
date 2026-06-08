<?php

namespace App\Models\Training;

use App\Models\AppModel;
use App\Models\Config\Department;
use App\Models\Position;
use App\Models\SelfService\Employee;
use App\Traits\HasApprovalUpdates;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PreviousPosition extends AppModel
{
    use HasFactory, SoftDeletes, HasUuid, HasApprovalUpdates;

    protected $fillable = [
        'employee_id',
        'position_id',
        'department_id',
        'start',
        'end',
        'user_id'
    ];

    public function approvableFields(): array
    {
        return [
            'employee_id' => ['show_in_diff' => false],
            'position_id' => ['show_in_diff' => false],
            'department_id' => ['show_in_diff' => false],
            'start' => ['show_in_diff' => true],
            'end' => ['show_in_diff' => true],
            'user_id' => ['show_in_diff' => false],
        ];
    }

    /**
     * @return BelongsTo
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * @return BelongsTo
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
