<?php

use App\Http\Controllers\PublicController;

Route::prefix('pub')->group(function () {
    Route::get('/employees', [PublicController::class, 'getEmployees']);
});
