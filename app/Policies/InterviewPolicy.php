<?php

namespace App\Policies;

use App\Models\Recruitment\Interview;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InterviewPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('schedule-interview');
    }

    public function view(User $user, Interview $interview): bool
    {
        return $user->can('schedule-interview');
    }

    public function create(User $user): bool
    {
        return $user->can('schedule-interview');
    }

    public function update(User $user, Interview $interview): bool
    {
        return $user->can('schedule-interview');
    }

    public function delete(User $user, Interview $interview): bool
    {
        return $user->can('schedule-interview');
    }
}
