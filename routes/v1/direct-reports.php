<?php

use App\Http\Controllers\DirectReportController;
use Illuminate\Support\Facades\Route;

// Reporting lines are managed by HR; index() lets supervisors list only their own reports.
Route::middleware('feature:direct_reports.enabled')
    ->apiResource('/direct-reports', DirectReportController::class)
    ->middlewareFor(['store', 'update', 'destroy'], 'permission:edit-employee');
