<?php

use App\Http\Controllers\SelfService\PreviousPositionController;
use App\Http\Controllers\SelfService\PreviousRankController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:training.enabled')->group(function () {
    Route::middleware('feature:training.previous_ranks')
        ->apiResource('/previous-ranks', PreviousRankController::class);

    Route::middleware('feature:training.previous_positions')
        ->apiResource('/previous-positions', PreviousPositionController::class);
});
