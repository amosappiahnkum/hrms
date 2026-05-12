<?php

use App\Services\SettingService;

if (! function_exists('setting')) {

    function setting(
        string $key,
        mixed $default = null
    ): mixed {

        return app(SettingService::class)
            ->get($key, $default);
    }
}

if (! function_exists('feature')) {

    function feature(string $feature, bool $default = false): bool {

        return app(SettingService::class)->feature($feature, $default);
    }
}

if (! function_exists('config_value')) {

    function config_value(
        string $key,
        mixed $default = null
    ): mixed {

        return setting($key, $default);
    }
}

if (! function_exists('module_config')) {

    function module_config(
        string $module
    ): array {

        return app(SettingService::class)
            ->module($module);
    }
}

if (! function_exists('setting_bool')) {

    function setting_bool(
        string $key,
        bool $default = false
    ): bool {

        return (bool) setting($key, $default);
    }
}

if (! function_exists('setting_int')) {

    function setting_int(
        string $key,
        int $default = 0
    ): int {

        return (int) setting($key, $default);
    }
}

if (! function_exists('setting_string')) {

    function setting_string(
        string $key,
        string $default = ''
    ): string {

        return (string) setting($key, $default);
    }
}

if (! function_exists('setting_array')) {

    function setting_array(
        string $key,
        array $default = []
    ): array {

        return (array) setting($key, $default);
    }
}
