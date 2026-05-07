<?php
use App\Http\Controllers\QuestionCategoryController;
use App\Http\Controllers\QuestionController;
use Illuminate\Support\Facades\Route;

Route::apiResource('categories', QuestionCategoryController::class);

Route::apiResource('questions', QuestionController::class);
Route::post('questions/reorder', [QuestionController::class, 'reorder']);
Route::post('questions/{question}/toggle-active', [QuestionController::class, 'toggleActive']);
