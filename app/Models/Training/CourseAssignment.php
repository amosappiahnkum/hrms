<?php

namespace App\Models\Training;

use App\Models\ApplicationModel;
use App\Models\Config\Department;
use App\Models\JobCategory;
use App\Models\SelfService\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseAssignment extends ApplicationModel
{
    protected $fillable = [
        'course_id',
        'scope_type',
        'scope_ids',
        'due_date',
    ];

    protected $casts = [
        'scope_ids' => 'array',
        'due_date'  => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * What this group targets, with names for display: [{value, label}] in saved order.
     * Items deleted since keep their place, marked as removed.
     */
    public function targets(): array
    {
        $ids = $this->scope_ids ?? [];
        if ($this->scope_type === 'all' || $ids === []) {
            return [];
        }

        $labels = match ($this->scope_type) {
            'department'   => Department::withTrashed()->whereIn('uuid', $ids)->pluck('name', 'uuid'),
            'job_category' => JobCategory::withTrashed()->whereIn('uuid', $ids)->pluck('name', 'uuid'),
            'role'         => collect($ids)->mapWithKeys(fn ($role) => [$role => $role]),
            'employee'     => Employee::withTrashed()->whereIn('uuid', $ids)
                ->get(['uuid', 'first_name', 'middle_name', 'last_name', 'staff_id'])
                ->mapWithKeys(fn (Employee $e) => [
                    $e->uuid => trim(preg_replace('/\s+/', ' ', $e->name)) . ($e->staff_id ? " ({$e->staff_id})" : ''),
                ]),
            default        => collect(),
        };

        return collect($ids)
            ->map(fn ($id) => ['value' => $id, 'label' => $labels[$id] ?? 'Removed'])
            ->values()
            ->all();
    }
}
