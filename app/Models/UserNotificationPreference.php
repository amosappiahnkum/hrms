<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'notification_type',
        'email',
        'in_app',
    ];

    protected $casts = [
        'email' => 'boolean',
        'in_app' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Returns the resolved channel list for $notifiable based on their saved preferences.
     * Falls back to the registry defaults when no row exists for this type.
     *
     * @param  mixed   $notifiable  A User model instance (the notification recipient)
     * @param  string  $type        A key from config/notification_types.php
     * @param  array   $fallback    Channels to return when the user has no preference stored
     */
    public static function channelsFor(mixed $notifiable, string $type, array $fallback = ['mail']): array
    {
        if (!$notifiable || !isset($notifiable->id)) {
            return $fallback;
        }

        $pref = static::where('user_id', $notifiable->id)
            ->where('notification_type', $type)
            ->first();

        // No stored preference — use config defaults
        if (!$pref) {
            $defaults = static::defaultsForType($type);
            $channels = [];
            if ($defaults['default_email'] ?? true) $channels[] = 'mail';
            if ($defaults['default_in_app'] ?? false) $channels[] = 'database';
            return $channels ?: $fallback;
        }

        $channels = [];
        if ($pref->email) $channels[] = 'mail';
        if ($pref->in_app) $channels[] = 'database';

        return $channels;
    }

    /** Resolve default_email / default_in_app from the registry for a given type key. */
    public static function defaultsForType(string $type): array
    {
        foreach (config('notification_types', []) as $group) {
            if (isset($group['types'][$type])) {
                return $group['types'][$type];
            }
        }
        return ['default_email' => true, 'default_in_app' => false];
    }
}
