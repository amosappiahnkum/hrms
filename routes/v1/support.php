<?php

use App\Http\Controllers\SupportController;
use Illuminate\Support\Facades\Route;

Route::post('support', [SupportController::class, 'submit']);
Route::post('support/resolve', [SupportController::class, 'resolve']);
