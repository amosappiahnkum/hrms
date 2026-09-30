<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

class TrackSuccessfulLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        // The sign-in itself is audited by RecordAuthenticationActivity.
        $user->update(['last_login_at' => now()]);
    }
}
