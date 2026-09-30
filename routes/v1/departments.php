<?php

use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\PositionController;
use Illuminate\Support\Facades\Route;

Route::get('departments/search', [DepartmentController::class, 'searchDepartments']);
Route::apiResource('departments', DepartmentController::class)->only(['index', 'show']);
Route::apiResource('positions', PositionController::class)->only(['index']);

Route::apiResource('departments', DepartmentController::class)
    ->only(['store', 'update', 'destroy'])
    ->middlewareFor('store', 'permission:add-department')
    ->middlewareFor('update', 'permission:edit-department')
    ->middlewareFor('destroy', 'permission:delete-department');

Route::apiResource('positions', PositionController::class)
    ->only(['store', 'update', 'destroy'])
    ->middleware('permission:manage-positions');
