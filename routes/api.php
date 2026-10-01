<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Recruitment\CandidatePortalController;
use App\Http\Controllers\Recruitment\PublicJobController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:20,1')->get('scan/{token}', [AuthController::class, 'qrCodeScan']);

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::get('settings/public', [SettingController::class, 'public']);

    foreach (glob(__DIR__ . '/staff-directory/*.php') as $file) {
        require $file;
    }

    // Public job board and candidate sign-up/login.
    Route::prefix('public')->middleware(['throttle:30,1', 'feature:recruitment.enabled,recruitment.public_portal'])->group(function () {
        Route::get('jobs', [PublicJobController::class, 'index']);
        Route::get('jobs/{jobOpening}', [PublicJobController::class, 'show']);
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('candidate/register', [CandidatePortalController::class, 'register']);
            Route::post('candidate/login', [CandidatePortalController::class, 'login']);
        });
    });
});

Route::prefix('v1')
    ->middleware(['auth:candidate', 'feature:recruitment.enabled,recruitment.public_portal'])
    ->group(function () {
        require __DIR__ . '/v1/recruitment-portal.php';
    });

Route::prefix('v1')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        foreach (glob(__DIR__ . '/v1/*.php') as $file) {
            if (basename($file) === 'recruitment-portal.php') continue;
            require $file;
        }
    });
