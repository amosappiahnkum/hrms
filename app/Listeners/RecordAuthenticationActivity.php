<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for authentication: sign-ins, sign-outs, failed attempts, lockouts and password
 * resets are written to activity_log (log name "auth"). Passwords are never recorded.
 */
class RecordAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        $this->record('login', 'Signed in', $event->user, ['guard' => $event->guard, 'remember' => $event->remember]);
    }

    public function handleLogout(Logout $event): void
    {
        $this->record('logout', 'Signed out', $event->user, ['guard' => $event->guard]);
    }

    public function handleFailed(Failed $event): void
    {
        $identifier = $event->credentials['email'] ?? $event->credentials['username'] ?? null;

        // Resolve the account on a wrong password; unknown usernames are recorded too.
        $user = $event->user ?? ($identifier
            ? User::where('email', $identifier)->orWhere('username', $identifier)->first()
            : null);

        $this->record('login_failed', 'Failed sign-in attempt', $user, [
            'guard'          => $event->guard,
            'username'       => $identifier,
            'account_exists' => $user !== null,
        ]);
    }

    public function handleLockout(Lockout $event): void
    {
        $this->record('lockout', 'Too many sign-in attempts', null, [
            'username' => $event->request->input('username') ?? $event->request->input('email'),
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->record('password_reset', 'Password reset', $event->user);
    }

    private function record(string $event, string $description, $user, array $properties = []): void
    {
        $request = request();

        $logger = activity('auth')
            ->event($event)
            ->withProperties($properties + ['ip' => $request?->ip(), 'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255)]);

        if ($user instanceof Model) {
            $logger->causedBy($user)->performedOn($user);
        }

        $logger->log($description);
    }
}
