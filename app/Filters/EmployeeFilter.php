<?php

namespace App\Filters;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class EmployeeFilter
{
    public function __construct(
        protected Request $request
    ) {}

    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->archived(), fn ($q) => $q->onlyTrashed())
            ->when($this->department(), fn ($q, $value) => $q->where('department_id', $value))
            ->when($this->gender(), fn ($q, $value) => $q->where('gender', $value))
            ->when($this->maritalStatus(), fn ($q, $value) => $q->where('marital_status', $value))
            ->when($this->rank(), fn ($q, $value) => $q->where('rank_id', $value))
            ->when($this->jobCategory(), fn ($q, $value) =>
            $q->whereHas('jobDetail', fn ($q2) =>
            $q2->where('job_category_id', $value)
            )
            )
            ->when($this->search(), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('staff_id', 'like', "%{$search}%");
                });
            });
    }

    protected function archived(): bool
    {
        return $this->request->boolean('archived');
    }

    protected function department(): ?string
    {
        return $this->value('department');
    }

    protected function gender(): ?string
    {
        return $this->value('gender');
    }

    protected function maritalStatus(): ?string
    {
        return $this->value('marital_status');
    }

    protected function rank(): ?string
    {
        return $this->value('rank_id');
    }

    protected function jobCategory(): ?string
    {
        return $this->value('job_category_id');
    }

    protected function search(): ?string
    {
        return $this->request->filled('search')
            ? $this->request->search
            : null;
    }

    protected function value(string $key): ?string
    {
        return $this->request->filled($key) && $this->request->$key !== 'all'
            ? $this->request->$key
            : null;
    }
}
