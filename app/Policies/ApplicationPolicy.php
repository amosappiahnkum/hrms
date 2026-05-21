<?php

namespace App\Policies;

use App\Models\Recruitment\Application;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ApplicationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('manage-applications');
    }

    public function view(User $user, Application $application): bool
    {
        return $user->can('manage-applications');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-applications');
    }

    public function update(User $user, Application $application): bool
    {
        return $user->can('manage-applications');
    }

    public function delete(User $user, Application $application): bool
    {
        return $user->can('manage-applications');
    }
}
