<?php

namespace App\Policies;

use App\Models\Recruitment\JobOpening;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class JobOpeningPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view-job-opening');
    }

    public function view(User $user, JobOpening $jobOpening): bool
    {
        return $user->can('view-job-opening');
    }

    public function create(User $user): bool
    {
        return $user->can('add-job-opening');
    }

    public function update(User $user, JobOpening $jobOpening): bool
    {
        return $user->can('edit-job-opening');
    }

    public function delete(User $user, JobOpening $jobOpening): bool
    {
        return $user->can('delete-job-opening');
    }
}
