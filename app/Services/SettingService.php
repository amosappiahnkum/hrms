<?php

namespace App\Services;

use App\Models\Config\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SettingService
{
    protected Collection $settings;

    public function __construct()
    {
        $this->settings = Cache::rememberForever(

            'settings.all',

            fn() => Setting::query()
                ->get()
                ->keyBy('key')
        );
    }

    public function get(
        string $key,
        mixed  $default = null
    ): mixed
    {

        return $this->settings
            ->get($key)
            ?->value ?? $default;
    }

    public function set(
        string  $key,
        mixed   $value,
        ?string $group = null,
        ?string $description = null,
        bool    $isPublic = false
    ): Setting
    {

        $setting = Setting::updateOrCreate(
            ['key' => $key],
            [

                'value' => $value,

                'group' => $group,

                'description' => $description,

                'is_public' => $isPublic,
            ]
        );

        $this->refreshCache();

        return $setting;
    }

    /**
     * Insert a setting only if the key does not already exist.
     * Safe to call repeatedly — never overwrites a customised value.
     */
    public function setDefault(
        string  $key,
        mixed   $value,
        ?string $group = null,
        ?string $description = null,
        bool    $isPublic = false
    ): Setting
    {
        $setting = Setting::firstOrCreate(
            ['key' => $key],
            [
                'value'       => $value,
                'group'       => $group,
                'description' => $description,
                'is_public'   => $isPublic,
            ]
        );

        if ($setting->wasRecentlyCreated) {
            $this->refreshCache();
        }

        return $setting;
    }

    public function group(
        string $group
    ): Collection
    {

        return $this->settings
            ->where('group', $group);
    }

    public function public(): Collection
    {
        return $this->settings->where('is_public', true);
    }

    public function feature(string $feature, bool $default = false): bool
    {
        return (bool)$this->get("features.$feature", $default);
    }

    public function features(): array
    {
        return $this->module('features');
    }

    public function refreshCache(): void
    {
        Cache::forget('settings.all');

        $this->settings = Cache::rememberForever(

            'settings.all',

            fn() => Setting::query()
                ->get()
                ->keyBy('key')
        );
    }


    public function module(string $module): array
    {

        return $this->settings
            ->filter(function ($setting, $key) use ($module) {

                return str_starts_with(
                    $key,
                    "{$module}."
                );
            })
            ->mapWithKeys(function ($setting, $key) use ($module) {

                return [

                    str_replace(
                        "{$module}.",
                        '',
                        $key
                    ) => $setting->value
                ];
            })
            ->toArray();
    }
}
