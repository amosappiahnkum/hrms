<?php

use App\Http\Controllers\TrainingPlan\TrainingCatalogueController;
use App\Http\Controllers\TrainingPlan\TrainingDomainController;
use App\Http\Controllers\TrainingPlan\TrainingEvaluationController;
use App\Http\Controllers\TrainingPlan\TrainingNeedsController;
use App\Http\Controllers\TrainingPlan\TrainingPlanApprovalLevelController;
use App\Http\Controllers\TrainingPlan\TrainingPlanController;
use App\Http\Controllers\TrainingPlan\TrainingPlanItemController;
use Illuminate\Support\Facades\Route;

// Permission holders see whole plans; heads of department see (and, while a plan is collecting,
// add) their own staff's trainings. Controllers narrow what each one sees.
Route::middleware('feature:training_plan.enabled')->prefix('training-plan')->group(function () {
    // Self-service: every employee sees their own approved trainings.
    Route::get('my-items', [TrainingPlanItemController::class, 'mine']);
    // Self-service: feedback on trainings the user completed, and reviews of their staff's.
    Route::get('my-evaluations', [TrainingEvaluationController::class, 'mine']);
    Route::put('evaluations/{trainingEvaluation}', [TrainingEvaluationController::class, 'submit']);

    Route::middleware('training-plan.access:manage-certifications')->group(function () {
        Route::get('options', [TrainingPlanController::class, 'options']);
    });
    // For linking a certificate to the training it came from.
    Route::middleware('permission:view-training-plan|prepare-training-plan|review-training-plan|manage-certifications')
        ->get('employee-items', [TrainingPlanItemController::class, 'forEmployee']);

    Route::middleware('training-plan.access')->group(function () {
        Route::get('access', [TrainingPlanController::class, 'access']);
        Route::get('catalogue', [TrainingCatalogueController::class, 'index']);
        Route::get('domains', [TrainingDomainController::class, 'index']);

        Route::get('plans', [TrainingPlanController::class, 'index']);
        Route::get('plans/{trainingPlan}', [TrainingPlanController::class, 'show']);
        Route::get('plans/{trainingPlan}/dashboard', [TrainingPlanController::class, 'dashboard']);
        Route::get('plans/{trainingPlan}/items', [TrainingPlanItemController::class, 'index']);
        Route::get('plans/{trainingPlan}/trainings', [TrainingPlanItemController::class, 'trainings']);

        // HR while planning; heads of department for their staff while the plan is collecting.
        Route::post('plans/{trainingPlan}/items', [TrainingPlanItemController::class, 'store']);
        Route::post('plans/{trainingPlan}/trainings/trainees', [TrainingPlanItemController::class, 'addTrainees']);
        Route::put('items/{trainingPlanItem}', [TrainingPlanItemController::class, 'update']);
        Route::delete('items/{trainingPlanItem}', [TrainingPlanItemController::class, 'destroy']);
        Route::get('team/employees', [TrainingPlanItemController::class, 'teamEmployees']);

        // The people in the level the plan is waiting on (checked by ApprovalService).
        Route::post('plans/{trainingPlan}/sign-off', [TrainingPlanController::class, 'signOff']);
        Route::post('plans/{trainingPlan}/reject', [TrainingPlanController::class, 'reject']);
    });

    Route::middleware('permission:prepare-training-plan')->group(function () {
        Route::post('catalogue', [TrainingCatalogueController::class, 'store']);
        Route::put('catalogue/{trainingCatalogueItem}', [TrainingCatalogueController::class, 'update']);
        Route::delete('catalogue/{trainingCatalogueItem}', [TrainingCatalogueController::class, 'destroy']);

        Route::post('domains', [TrainingDomainController::class, 'store']);
        Route::put('domains/{trainingDomain}', [TrainingDomainController::class, 'update']);
        Route::delete('domains/{trainingDomain}', [TrainingDomainController::class, 'destroy']);

        Route::post('plans', [TrainingPlanController::class, 'store']);
        Route::put('plans/{trainingPlan}', [TrainingPlanController::class, 'update']);
        Route::delete('plans/{trainingPlan}', [TrainingPlanController::class, 'destroy']);
        Route::put('plans/{trainingPlan}/collection', [TrainingPlanController::class, 'openCollection']);
        Route::delete('plans/{trainingPlan}/collection', [TrainingPlanController::class, 'closeCollection']);
        Route::post('plans/{trainingPlan}/submit', [TrainingPlanController::class, 'submit']);
        Route::post('plans/{trainingPlan}/revise', [TrainingPlanController::class, 'revise']);

        // Training needs handed over from the competency matrix, and planning them in bulk.
        Route::get('needs', [TrainingNeedsController::class, 'index']);
        Route::post('plans/{trainingPlan}/needs', [TrainingPlanItemController::class, 'planNeeds']);

        // Record one session (attendance, dates, hours, results) for several trainees at once.
        Route::post('plans/{trainingPlan}/items/progress', [TrainingPlanItemController::class, 'recordProgress']);
    });

    // Who validates and approves.
    Route::middleware('permission:configure-training-plan-approvals')->prefix('approval-levels')->group(function () {
        Route::get('/', [TrainingPlanApprovalLevelController::class, 'index']);
        Route::get('candidates', [TrainingPlanApprovalLevelController::class, 'candidates']);
        Route::post('/', [TrainingPlanApprovalLevelController::class, 'store']);
        Route::put('order', [TrainingPlanApprovalLevelController::class, 'reorder']);
        Route::put('{trainingPlanApprovalLevel}', [TrainingPlanApprovalLevelController::class, 'update']);
        Route::delete('{trainingPlanApprovalLevel}', [TrainingPlanApprovalLevelController::class, 'destroy']);
    });
});
