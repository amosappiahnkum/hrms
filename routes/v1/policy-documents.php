<?php

use App\Http\Controllers\PolicyDocumentController;
use Illuminate\Support\Facades\Route;

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
