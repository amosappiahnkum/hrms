<?php

use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('user/settings')->group(function () {
    Route::get('notifications', [UserSettingsController::class, 'getNotificationPreferences']);
    Route::patch('notifications', [UserSettingsController::class, 'updateNotificationPreferences']);
});

Route::get('features', [SettingController::class, 'indexFeatures']);
Route::patch('features/{key}', [SettingController::class, 'updateFeature'])->where('key', '.+');

Route::get('settings/app', [SettingController::class, 'indexApp']);
Route::patch('settings/app', [SettingController::class, 'updateApp']);
