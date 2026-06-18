<?php

namespace App\Models\Config;

use App\Models\ApplicationModel;
use App\Models\SelfService\Employee;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends ApplicationModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'name', 'user_id', 'hod', 'parent_department_id',
    ];

    protected $casts = [
        'id' => 'integer',
    ];

    public function headOfDepartment(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hod');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_department_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
