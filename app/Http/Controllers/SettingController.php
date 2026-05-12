<?php

namespace App\Http\Controllers;

use App\Models\Config\Setting;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    // Public — no auth required (branding, app name, etc.)
    public function public(): JsonResponse
    {
        $settings = Setting::query()
            ->where('is_public', true)
            ->get()
            ->groupBy('group')
            ->map(fn($group) => $group->mapWithKeys(fn($s) => [
                last(explode('.', $s->key)) => $s->value,
            ]));

        return response()->json(['data' => $settings]);
    }

    // App config CRUD — super-admin only
    public function indexApp(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->module('app'),
        ]);
    }

    public function updateApp(Request $request): JsonResponse
    {
        if (! $this->hasRole('super-admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'settings'       => ['required', 'array'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['required'],
        ]);

        foreach ($validated['settings'] as $item) {
            $this->settings->set("app.{$item['key']}", $item['value'], 'app', isPublic: true);
        }

        return response()->json([
            'data' => $this->settings->module('app'),
        ]);
    }

    // Feature flags
    public function indexFeatures(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->features(),
        ]);
    }

    public function updateFeature(Request $request, string $key): JsonResponse
    {
        if (! $this->hasRole('super-admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->settings->set("features.{$key}", $validated['enabled']);

        return response()->json([
            'data' => [
                'key'     => $key,
                'enabled' => $validated['enabled'],
            ],
        ]);
    }
}
