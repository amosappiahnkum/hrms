<?php

use App\Http\Controllers\InformationUpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:information_updates.enabled')->group(function () {
    Route::apiResource('information-updates', InformationUpdateController::class);
    Route::prefix('approvals')->group(function () {
        Route::get('/', [InformationUpdateController::class, 'index']);
        Route::get('mine', [InformationUpdateController::class, 'myRequest']);
        Route::get('/{information_update}', [InformationUpdateController::class, 'show']);
        Route::post('/{information_update}/approve', [InformationUpdateController::class, 'approve']);
        Route::post('/{information_update}/reject', [InformationUpdateController::class, 'reject']);
    });
});
