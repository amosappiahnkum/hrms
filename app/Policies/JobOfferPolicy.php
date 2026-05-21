<?php

namespace App\Policies;

use App\Models\Recruitment\JobOffer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class JobOfferPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('make-offer');
    }

    public function view(User $user, JobOffer $offer): bool
    {
        return $user->can('make-offer');
    }

    public function create(User $user): bool
    {
        return $user->can('make-offer');
    }

    public function update(User $user, JobOffer $offer): bool
    {
        return $user->can('make-offer');
    }

    public function delete(User $user, JobOffer $offer): bool
    {
        return $user->can('make-offer');
    }
}
