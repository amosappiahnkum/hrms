<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\Config\Setting;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    public function public(): JsonResponse
    {
        $settings = Setting::query()
            ->where(function ($query) {
                $query->where('is_public', true)
                    ->orWhereIn('key', [
                        'company.name',
                        'company.abbreviation',
                        'company.logo_url',
                        'company.tagline',
                    ]);
            })
            ->get();

        $grouped = $settings
            ->where('is_public', true)
            ->groupBy('group')
            ->map(function ($group) {
                return $group->mapWithKeys(function ($setting) {
                    return [
                        Str::afterLast($setting->key, '.') => $setting->value,
                    ];
                });
            });

        $company = $settings
            ->whereIn('key', [
                'company.name',
                'company.abbreviation',
                'company.logo_url',
                'company.tagline',
            ])
            ->mapWithKeys(function ($setting) {

                $key = Str::afterLast($setting->key, '.');

                $value = $setting->value;

                if ($key === 'logo_url' && $value) {
                    $value = Helper::getTempPhoto($value, 'common');
                }

                return [$key => $value];
            });

        return response()->json([
            'data' => [
                ...$grouped->toArray(),
                'company' => $company->toArray(),
            ],
        ]);
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
