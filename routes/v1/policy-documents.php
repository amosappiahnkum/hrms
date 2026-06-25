<?php

use App\Http\Controllers\DocumentCategoryController;
use App\Http\Controllers\PolicyDocumentController;
use Illuminate\Support\Facades\Route;

// ── Document categories ───────────────────────────────────────────────────────
Route::prefix('document-categories')->group(function () {
    Route::get('/', [DocumentCategoryController::class, 'index']);
    Route::post('/', [DocumentCategoryController::class, 'store']);
    Route::put('/{documentCategory}', [DocumentCategoryController::class, 'update']);
    Route::delete('/{documentCategory}', [DocumentCategoryController::class, 'destroy']);
});

// ── Admin ─────────────────────────────────────────────────────────────────────
Route::prefix('policy-documents')->group(function () {
    Route::get('/', [PolicyDocumentController::class, 'index']);
    Route::post('/', [PolicyDocumentController::class, 'store']);
    Route::put('/{policyDocument}', [PolicyDocumentController::class, 'update']);
    Route::delete('/{policyDocument}', [PolicyDocumentController::class, 'destroy']);
    Route::get('/{policyDocument}/view', [PolicyDocumentController::class, 'view']);
});

// ── Employee self-service ─────────────────────────────────────────────────────
Route::prefix('my/documents')->group(function () {
    Route::get('/', [PolicyDocumentController::class, 'myDocuments']);
    Route::get('/{policyDocument}/view', [PolicyDocumentController::class, 'myView']);
});
