<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

class TrackSuccessfulLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        $user->update(['last_login_at' => now()]);

        activity('auth')
            ->performedOn($user)
            ->causedBy($user)
            ->log("{$user->name} logged in");
    }
}
