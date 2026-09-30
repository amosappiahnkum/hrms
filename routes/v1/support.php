<?php

use App\Http\Controllers\SupportController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:5,1')->post('support', [SupportController::class, 'submit']);
Route::middleware('permission:resolve-support-tickets')->post('support/resolve', [SupportController::class, 'resolve']);
