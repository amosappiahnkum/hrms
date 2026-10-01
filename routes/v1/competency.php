<?php

use App\Http\Controllers\Competency\CompetencyAssessmentController;
use App\Http\Controllers\Competency\CompetencyController;
use App\Http\Controllers\Competency\CompetencyMatrixController;
use App\Http\Controllers\Competency\DevelopmentActionController;
use App\Http\Controllers\Competency\EmployeeCompetencyController;
use App\Http\Controllers\Competency\PositionCompetencyController;
use App\Http\Middleware\CompetencyAccess;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:competency.enabled')->prefix('competency')->group(function () {
    // Self-service: every employee sees their own competency profile.
    Route::get('mine', [EmployeeCompetencyController::class, 'mine']);

    // Competency permission holders (everyone) and team leaders (their team). Who may see or
    // assess which employee is then checked per employee.
    Route::middleware(CompetencyAccess::class)->group(function () {
        Route::get('options', [CompetencyController::class, 'options']);
        Route::get('competencies', [CompetencyController::class, 'index']);
        Route::get('positions', [PositionCompetencyController::class, 'index']);
        Route::get('positions/{position}', [PositionCompetencyController::class, 'show']);

        Route::get('matrix', [CompetencyMatrixController::class, 'matrix']);
        Route::get('gaps', [CompetencyMatrixController::class, 'gaps']);
        Route::get('summary', [CompetencyMatrixController::class, 'summary']);

        Route::get('employees/{employee}', [EmployeeCompetencyController::class, 'show']);
        Route::get('assessments/{competencyAssessment}', [CompetencyAssessmentController::class, 'show']);

        Route::post('employees/{employee}/assessments', [CompetencyAssessmentController::class, 'start']);
        Route::put('assessments/{competencyAssessment}', [CompetencyAssessmentController::class, 'update']);
        Route::post('assessments/{competencyAssessment}/complete', [CompetencyAssessmentController::class, 'complete']);
        Route::delete('assessments/{competencyAssessment}', [CompetencyAssessmentController::class, 'destroy']);

        Route::post('actions', [DevelopmentActionController::class, 'store']);
        Route::put('actions/{developmentAction}', [DevelopmentActionController::class, 'update']);
        Route::delete('actions/{developmentAction}', [DevelopmentActionController::class, 'destroy']);
    });

    Route::middleware('permission:manage-competencies')->group(function () {
        Route::post('competencies', [CompetencyController::class, 'store']);
        Route::put('competencies/{competency}', [CompetencyController::class, 'update']);
        Route::delete('competencies/{competency}', [CompetencyController::class, 'destroy']);
        Route::put('positions/{position}', [PositionCompetencyController::class, 'sync']);
    });
});
