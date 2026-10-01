<?php

use App\Http\Controllers\TrainingPlan\TrainingCatalogueController;
use App\Http\Controllers\TrainingPlan\TrainingPlanController;
use App\Http\Controllers\TrainingPlan\TrainingPlanItemController;
use Illuminate\Support\Facades\Route;

// Anyone taking part in training plans may read them.
$viewers = 'permission:view-training-plan|prepare-training-plan|validate-training-plan|approve-training-plan';

Route::middleware('feature:training_plan.enabled')->prefix('training-plan')->group(function () use ($viewers) {
    // Self-service: every employee sees their own approved trainings.
    Route::get('my-items', [TrainingPlanItemController::class, 'mine']);

    Route::middleware("{$viewers}|manage-certifications")->group(function () {
        Route::get('options', [TrainingPlanController::class, 'options']);
        // For linking a certificate to the training it came from.
        Route::get('employee-items', [TrainingPlanItemController::class, 'forEmployee']);
    });

    // ── Catalogue ─────────────────────────────────────────────────────────────
    Route::middleware($viewers)->get('catalogue', [TrainingCatalogueController::class, 'index']);
    Route::middleware('permission:prepare-training-plan')->group(function () {
        Route::post('catalogue', [TrainingCatalogueController::class, 'store']);
        Route::put('catalogue/{trainingCatalogueItem}', [TrainingCatalogueController::class, 'update']);
        Route::delete('catalogue/{trainingCatalogueItem}', [TrainingCatalogueController::class, 'destroy']);
    });

    // ── Plans ─────────────────────────────────────────────────────────────────
    Route::middleware($viewers)->group(function () {
        Route::get('plans', [TrainingPlanController::class, 'index']);
        Route::get('plans/{trainingPlan}', [TrainingPlanController::class, 'show']);
        Route::get('plans/{trainingPlan}/dashboard', [TrainingPlanController::class, 'dashboard']);
        Route::get('plans/{trainingPlan}/items', [TrainingPlanItemController::class, 'index']);
        Route::get('plans/{trainingPlan}/trainings', [TrainingPlanItemController::class, 'trainings']);
    });

    Route::middleware('permission:prepare-training-plan')->group(function () {
        Route::post('plans', [TrainingPlanController::class, 'store']);
        Route::put('plans/{trainingPlan}', [TrainingPlanController::class, 'update']);
        Route::delete('plans/{trainingPlan}', [TrainingPlanController::class, 'destroy']);
        Route::post('plans/{trainingPlan}/submit', [TrainingPlanController::class, 'submit']);
        Route::post('plans/{trainingPlan}/revise', [TrainingPlanController::class, 'revise']);

        Route::post('plans/{trainingPlan}/items', [TrainingPlanItemController::class, 'store']);
        Route::post('plans/{trainingPlan}/trainings/trainees', [TrainingPlanItemController::class, 'addTrainees']);
        Route::put('items/{trainingPlanItem}', [TrainingPlanItemController::class, 'update']);
        Route::delete('items/{trainingPlanItem}', [TrainingPlanItemController::class, 'destroy']);
    });

    Route::middleware('permission:validate-training-plan')->group(function () {
        Route::post('plans/{trainingPlan}/validate', [TrainingPlanController::class, 'validatePlan']);
    });

    Route::middleware('permission:approve-training-plan')->group(function () {
        Route::post('plans/{trainingPlan}/approve', [TrainingPlanController::class, 'approve']);
    });

    // Validators reject at validation, approvers at approval — ApprovalService checks which applies.
    Route::middleware('permission:validate-training-plan|approve-training-plan')->group(function () {
        Route::post('plans/{trainingPlan}/reject', [TrainingPlanController::class, 'reject']);
    });
});
