<?php

use App\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::get('company/overview', [CompanyController::class, 'overview']);
