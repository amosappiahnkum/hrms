<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('user')->group(function () {
    Route::get('/{id}/roles/active', [UserController::class, 'getActiveRoles']);
    Route::get('/{id}/roles', [UserController::class, 'getUserRoles']);
});

Route::apiResource('/users', UserController::class);

Route::prefix('user-management')->group(function () {
    Route::get('/', [UserManagementController::class, 'index']);
    Route::get('roles', [UserManagementController::class, 'roles']);
    Route::get('permissions', [UserManagementController::class, 'permissions']);
    Route::get('employee/{employeeUuid}', [UserManagementController::class, 'showByEmployee']);
    Route::post('{uuid}/roles', [UserManagementController::class, 'syncRoles']);
    Route::post('{uuid}/permissions', [UserManagementController::class, 'syncPermissions']);
    Route::post('{uuid}/suspend', [UserManagementController::class, 'suspend']);
    Route::post('{uuid}/restore', [UserManagementController::class, 'restore']);
});
