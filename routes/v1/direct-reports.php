<?php

use App\Http\Controllers\DirectReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:direct_reports.enabled')
    ->apiResource('/direct-reports', DirectReportController::class);
