<?php

use App\Http\Controllers\CommonController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('commons', [HomeController::class, 'getCommonData']);
Route::get('educational-levels', [CommonController::class, 'getEducationalLevels']);
Route::get('notifications/navs', [CommonController::class, 'getNotificationNavs']);
Route::get('/stats/time-attendance', [CommonController::class, 'getTimeAndAttendanceDashboardData']);
Route::get('/stats/employee-management', [CommonController::class, 'getEmployeeManagementStats']);

Route::prefix('common')->group(function () {
    Route::get('permissions/{id}', [CommonController::class, 'getAllPermissions']);
    Route::post('permissions/assign', [CommonController::class, 'assignPermissions']);
});
