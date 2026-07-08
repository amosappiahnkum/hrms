<?php

use App\Http\Controllers\QuestionBank\QuestionCategoryController;
use App\Http\Controllers\QuestionBank\QuestionController;
use App\Http\Controllers\QuestionBank\QuestionOptionController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:question_bank.enabled')->group(function () {
    Route::apiResource('question-categories', QuestionCategoryController::class);
    Route::get('questions/template', [QuestionController::class, 'templateDownload']);
    Route::post('questions/import', [QuestionController::class, 'import']);
    Route::post('questions/reorder', [QuestionController::class, 'reorder']);
    Route::apiResource('questions', QuestionController::class);
    Route::post('questions/{question}/toggle-active', [QuestionController::class, 'toggleActive']);
    Route::apiResource('question-options', QuestionOptionController::class);
});
