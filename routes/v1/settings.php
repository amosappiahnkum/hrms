<?php

use App\Http\Controllers\AccessControlController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('user/settings')->group(function () {
    Route::get('notifications', [UserSettingsController::class, 'getNotificationPreferences']);
    Route::patch('notifications', [UserSettingsController::class, 'updateNotificationPreferences']);
});

Route::middleware('role:super-admin')->group(function () {
    Route::get('features', [SettingController::class, 'indexFeatures']);
    Route::patch('features/{key}', [SettingController::class, 'updateFeature'])->where('key', '.+');

    // Roles and permissions.
    Route::get('access', [AccessControlController::class, 'index']);
    Route::post('access/roles', [AccessControlController::class, 'storeRole']);
    Route::put('access/roles/{uuid}', [AccessControlController::class, 'updateRole']);
    Route::delete('access/roles/{uuid}', [AccessControlController::class, 'destroyRole']);
    Route::post('access/permissions', [AccessControlController::class, 'storePermission']);
    Route::delete('access/permissions/{uuid}', [AccessControlController::class, 'destroyPermission']);
});

Route::get('settings/app', [SettingController::class, 'indexApp']);
Route::patch('settings/app', [SettingController::class, 'updateApp']);
