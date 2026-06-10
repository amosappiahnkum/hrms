<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use Illuminate\Auth\Events\Login;

class TrackSuccessfulLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        $user->update(['last_login_at' => now()]);

        ActivityLog::add(
            "{$user->name} logged in",
            'login',
            [],
            'auth'
        )->to($user)->as($user);
    }
}
