<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;

trait EmployeeScope
{
    public function scopeArchived(Builder $query, bool $archived): Builder
    {
        return $archived ? $query->onlyTrashed() : $query;
    }

    public function scopeDepartment(Builder $query, ?string $department): Builder
    {
        return $department && $department !== 'all'
            ? $query->where('department_id', $department)
            : $query;
    }

    public function scopeGender(Builder $query, ?string $gender): Builder
    {
        return $gender && $gender !== 'all'
            ? $query->where('gender', $gender)
            : $query;
    }

    public function scopeMaritalStatus(Builder $query, ?string $status): Builder
    {
        return $status && $status !== 'all'
            ? $query->where('marital_status', $status)
            : $query;
    }

    public function scopeRank(Builder $query, ?string $rank): Builder
    {
        return $rank && $rank !== 'all'
            ? $query->where('rank_id', $rank)
            : $query;
    }

    public function scopeJobCategory(Builder $query, ?string $jobCategory): Builder
    {
        return $jobCategory && $jobCategory !== 'all'
            ? $query->whereHas('jobDetail', fn($q) => $q->where('job_category_id', $jobCategory)
            )
            : $query;
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (!$search) return $query;

        return $query->where(function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('middle_name', 'like', "%{$search}%")
                ->orWhere('staff_id', 'like', "%{$search}%");
        });
    }
}
