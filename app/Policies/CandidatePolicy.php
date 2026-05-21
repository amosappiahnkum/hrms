<?php

namespace App\Policies;

use App\Models\Recruitment\Candidate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CandidatePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('manage-candidates');
    }

    public function view(User $user, Candidate $candidate): bool
    {
        return $user->can('manage-candidates');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-candidates');
    }

    public function update(User $user, Candidate $candidate): bool
    {
        return $user->can('manage-candidates');
    }

    public function delete(User $user, Candidate $candidate): bool
    {
        return $user->can('manage-candidates');
    }
}
