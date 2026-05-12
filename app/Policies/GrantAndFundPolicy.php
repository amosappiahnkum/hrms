<?php

namespace App\Policies;

use App\Models\SelfService\GrantAndFund;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GrantAndFundPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        //
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\SelfService\GrantAndFund  $grantAndFund
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, GrantAndFund $grantAndFund)
    {
        //
    }

    /**
     * Determine whether the user can create models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        //
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\SelfService\GrantAndFund  $grantAndFund
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, GrantAndFund $grantAndFund)
    {
        return $user->id === $grantAndFund->user_id || $user->is_admin;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\SelfService\GrantAndFund  $grantAndFund
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, GrantAndFund $grantAndFund)
    {
        return $user->id === $grantAndFund->user_id || $user->is_admin;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\SelfService\GrantAndFund  $grantAndFund
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, GrantAndFund $grantAndFund)
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\SelfService\GrantAndFund  $grantAndFund
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, GrantAndFund $grantAndFund)
    {
        //
    }
}
