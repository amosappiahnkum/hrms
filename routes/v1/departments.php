<?php

use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\PositionController;
use Illuminate\Support\Facades\Route;

Route::get('departments/search', [DepartmentController::class, 'searchDepartments']);
Route::apiResource('departments', DepartmentController::class);
Route::apiResource('positions', PositionController::class)->except(['create', 'edit', 'show']);
