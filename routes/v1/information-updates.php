<?php

use App\Http\Controllers\InformationUpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:information_updates.enabled')->group(function () {
    // The requester can see their own requests; show() checks ownership.
    Route::get('approvals/mine', [InformationUpdateController::class, 'myRequest']);
    Route::get('approvals/{information_update}', [InformationUpdateController::class, 'show']);

    Route::middleware('permission:approve-employee-update')->group(function () {
        Route::apiResource('information-updates', InformationUpdateController::class);
        Route::prefix('approvals')->group(function () {
            Route::get('/', [InformationUpdateController::class, 'index']);
            Route::post('/{information_update}/approve', [InformationUpdateController::class, 'approve']);
            Route::post('/{information_update}/reject', [InformationUpdateController::class, 'reject']);
        });
    });
});
