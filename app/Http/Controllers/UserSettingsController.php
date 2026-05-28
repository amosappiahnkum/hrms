<?php

namespace App\Http\Controllers;

use App\Models\UserNotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Return all notification types (from registry) merged with the user's stored preferences.
     * Groups with their defaults baked in — the frontend only needs this one call.
     */
    public function getNotificationPreferences(): JsonResponse
    {
        $user = Auth::user();

        $stored = UserNotificationPreference::where('user_id', $user->id)
            ->get()
            ->keyBy('notification_type');

        $registry = config('notification_types', []);

        $groups = [];
        foreach ($registry as $groupKey => $group) {
            $types = [];
            foreach ($group['types'] as $typeKey => $typeDef) {
                $pref = $stored->get($typeKey);
                $types[] = [
                    'key' => $typeKey,
                    'label' => $typeDef['label'],
                    'description' => $typeDef['description'],
                    'email' => $pref ? $pref->email : $typeDef['default_email'],
                    'in_app' => $pref ? $pref->in_app : $typeDef['default_in_app'],
                ];
            }

            $groups[] = [
                'key' => $groupKey,
                'label' => $group['label'],
                'description' => $group['description'],
                'icon' => $group['icon'] ?? null,
                'types' => $types,
            ];
        }

        return response()->json($groups);
    }

    /**
     * Upsert the user's notification preferences.
     *
     * Expects: { preferences: [{ type: string, email: bool, in_app: bool }] }
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $request->validate([
            'preferences' => 'required|array',
            'preferences.*.type' => 'required|string',
            'preferences.*.email' => 'required|boolean',
            'preferences.*.in_app' => 'required|boolean',
        ]);

        $userId = Auth::id();

        foreach ($request->preferences as $pref) {
            UserNotificationPreference::updateOrCreate(
                ['user_id' => $userId, 'notification_type' => $pref['type']],
                ['email' => $pref['email'], 'in_app' => $pref['in_app']],
            );
        }

        return response()->json(['message' => 'Notification preferences saved.']);
    }
}
