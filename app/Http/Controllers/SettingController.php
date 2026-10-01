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
    public function __construct(private readonly SettingService $settings)
    {
    }

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
                    $value = Helper::getTempUrl($value, 'common');
                }

                return [$key => $value];
            });

        // Lets the public careers pages show "applications closed" instead of failing requests.
        $portal = Setting::query()
            ->whereIn('key', ['features.recruitment.enabled', 'features.recruitment.public_portal'])
            ->pluck('value', 'key');

        return response()->json([
            'data' => [
                ...$grouped->toArray(),
                'company' => $company->toArray(),
                'recruitment_portal_open' => (bool) ($portal['features.recruitment.enabled'] ?? false)
                    && (bool) ($portal['features.recruitment.public_portal'] ?? false),
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
        if (!$this->hasRole('super-admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'settings' => ['required', 'array'],
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
    /** Every feature flag with its module, for the super-admin feature toggle screen. */
    public function indexFeatures(): JsonResponse
    {
        $flags = Setting::query()
            ->where('key', 'like', 'features.%')
            ->orderBy('key')
            ->get()
            ->map(function (Setting $setting) {
                $key = substr($setting->key, strlen('features.'));
                [$module, $name] = array_pad(explode('.', $key, 2), 2, 'enabled');

                return [
                    'key'         => $key,
                    'module'      => $module,
                    'name'        => $name,
                    'enabled'     => (bool) $setting->value,
                    'description' => $setting->description,
                ];
            })
            ->values();

        return response()->json(['data' => $flags]);
    }

    public function updateFeature(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        // Only existing flags can be switched; a typo must not create a new one.
        abort_unless(Setting::where('key', "features.{$key}")->exists(), 404, 'Unknown feature.');

        $this->settings->set("features.{$key}", $validated['enabled']);

        activity('security')
            ->causedBy($request->user())
            ->event('feature_toggled')
            ->withProperties(['feature' => $key, 'enabled' => $validated['enabled']])
            ->log(($validated['enabled'] ? 'Enabled' : 'Disabled') . " feature {$key}");

        return response()->json([
            'data' => [
                'key' => $key,
                'enabled' => $validated['enabled'],
            ],
        ]);
    }
}
