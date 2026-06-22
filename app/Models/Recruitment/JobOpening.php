<?php

namespace App\Models\Recruitment;

use App\Enums\JobOpeningStatus;
use App\Models\ApplicationModel;
use App\Models\Config\Department;
use App\Models\Position;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobOpening extends ApplicationModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'title',
        'position_id',
        'department_id',
        'description',
        'requirements',
        'salary_min',
        'salary_max',
        'location',
        'status',
        'deadline',
        'user_id',
    ];

    protected $casts = [
        'status'     => JobOpeningStatus::class,
        'deadline'   => 'date',
        'salary_min' => 'decimal:2',
        'salary_max' => 'decimal:2',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
