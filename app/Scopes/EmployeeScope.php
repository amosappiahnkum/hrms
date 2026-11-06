<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;

trait EmployeeScope
{
    public function scopeArchived(Builder $query, bool $archived): Builder
    {
        return $archived ? $query->onlyTrashed() : $query;
    }

    /**
     * A filter's values: an array (department[]=3&department[]=5) or a comma list (department=3,5), without empties
     * and "all". Empty: no filter.
     */
    private static function filterValues(array|string|int|null $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $values), fn ($v) => $v !== '' && $v !== 'all'));
    }

    public function scopeDepartment(Builder $query, array|string|int|null $department): Builder
    {
        $ids = self::filterValues($department);

        return $ids
            // Each department and its sub-departments.
            ? $query->whereIn('department_id', collect($ids)->flatMap(fn ($id) => \App\Models\Config\Department::subtreeIds((int) $id))->unique()->all())
            : $query;
    }

    public function scopeGender(Builder $query, array|string|null $gender): Builder
    {
        $values = self::filterValues($gender);

        return $values ? $query->whereIn('gender', $values) : $query;
    }

    public function scopeMaritalStatus(Builder $query, array|string|null $status): Builder
    {
        $values = self::filterValues($status);

        return $values ? $query->whereIn('marital_status', $values) : $query;
    }

    public function scopeRank(Builder $query, array|string|int|null $rank): Builder
    {
        $ids = self::filterValues($rank);

        return $ids ? $query->whereIn('rank_id', $ids) : $query;
    }

    public function scopeJobCategory(Builder $query, array|string|int|null $jobCategory): Builder
    {
        $ids = self::filterValues($jobCategory);

        return $ids
            ? $query->whereHas('jobDetail', fn ($q) => $q->whereIn('job_category_id', $ids))
            : $query;
    }

    public function scopeJobType(Builder $query, array|string|null $jobType): Builder
    {
        $values = self::filterValues($jobType);

        return $values ? $query->whereIn('job_type', $values) : $query;
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (!$search) return $query;

        $terms = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($terms as $term) {
            $like = "%{$term}%";
            $query->where(function ($q) use ($like) {
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('staff_id', 'like', $like);
            });
        }

        return $query;
    }
}
