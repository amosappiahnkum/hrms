<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;

class TrackFailedLogin
{
    public function handle(Failed $event): void
    {
        $identifier = $event->credentials['email'] ?? $event->credentials['username'] ?? 'unknown';

        // Resolve the user if the account exists (wrong password scenario)
        $user = $event->user
            ?? User::where('email', $identifier)->orWhere('username', $identifier)->first();

        if (!$user) {
            return;
        }

        ActivityLog::add(
            "Failed login attempt for {$user->name} ({$identifier})",
            'failed_login',
            [],
            'auth'
        )->to($user)->as($user);
    }
}
