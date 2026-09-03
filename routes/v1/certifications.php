<?php

use App\Http\Controllers\CertificationProviderController;
use App\Http\Controllers\EmployeeCertificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:certifications.enabled')->group(function () {

    // ── Providers (for CreatableDropdownSearch) ───────────────────────────────
    Route::prefix('certification-providers')->group(function () {
        Route::get('/', [CertificationProviderController::class, 'index']);
        Route::post('/', [CertificationProviderController::class, 'store']);
    });

    // ── HR / Admin management ─────────────────────────────────────────────────
    Route::prefix('certifications')->group(function () {
        Route::get('/', [EmployeeCertificationController::class, 'index']);
        Route::post('/', [EmployeeCertificationController::class, 'store']);
        Route::get('/{employeeCertification}', [EmployeeCertificationController::class, 'show']);
        Route::put('/{employeeCertification}', [EmployeeCertificationController::class, 'update']);
        Route::delete('/{employeeCertification}', [EmployeeCertificationController::class, 'destroy']);
        Route::get('/{employeeCertification}/download', [EmployeeCertificationController::class, 'download']);
    });

    // ── Employee self-service ─────────────────────────────────────────────────
    Route::get('my/certifications', [EmployeeCertificationController::class, 'myCertifications']);
});
