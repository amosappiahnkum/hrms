<?php

use App\Http\Controllers\QuestionBank\QuestionCategoryController;
use App\Http\Controllers\QuestionBank\QuestionController;
use App\Http\Controllers\QuestionBank\QuestionOptionController;
use Illuminate\Support\Facades\Route;

Route::apiResource('question-categories', QuestionCategoryController::class);

Route::apiResource('questions', QuestionController::class);
Route::apiResource('question-options', QuestionOptionController::class);
Route::post('questions/reorder', [QuestionController::class, 'reorder']);
Route::post('questions/{question}/toggle-active', [QuestionController::class, 'toggleActive']);
