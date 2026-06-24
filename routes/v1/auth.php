<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImpersonationController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
Route::get('/me', [AuthController::class, 'me'])->name('api.me');
Route::post('/validate-auth', [AuthController::class, 'validateAuth'])->name('api.validate-auth');

Route::post('/auth/impersonate', [ImpersonationController::class, 'impersonate']);
Route::post('/auth/stop-impersonating', [ImpersonationController::class, 'stopImpersonating']);
