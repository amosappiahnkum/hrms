<?php

use App\Http\Controllers\Competency\CompetencyAssessmentController;
use App\Http\Controllers\Competency\AuthorizationActivityController;
use App\Http\Controllers\Competency\AuthorizationController;
use App\Http\Controllers\Competency\CertificationTypeController;
use App\Http\Controllers\Competency\CompetencyController;
use App\Http\Controllers\Competency\CompetencyEvidenceController;
use App\Http\Controllers\Competency\CompetencyMatrixController;
use App\Http\Controllers\Competency\DevelopmentActionController;
use App\Http\Controllers\Competency\EmployeeCompetencyController;
use App\Http\Controllers\Competency\PositionCompetencyController;
use App\Http\Middleware\CompetencyAccess;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:competency.enabled')->prefix('competency')->group(function () {
    // Self-service: every employee sees their own competency profile.
    Route::get('mine', [EmployeeCompetencyController::class, 'mine']);
    Route::get('mine/record', [EmployeeCompetencyController::class, 'myRecord']);
    // The development record PDF: the employee, or anyone who may see them (checked inside).
    Route::get('employees/{employee}/record', [EmployeeCompetencyController::class, 'record']);
    // Evidence on the employee's own record, or on someone the user may see (checked per file).
    Route::get('evidence/{competencyEvidenceFile}/download', [CompetencyEvidenceController::class, 'download']);

    // Competency permission holders (everyone) and team leaders (their team). Who may see or
    // assess which employee is then checked per employee.
    // The library, also for people preparing the training plan (they link trainings to competencies).
    Route::middleware(CompetencyAccess::class . ':library')->group(function () {
        Route::get('options', [CompetencyController::class, 'options']);
        Route::get('competencies', [CompetencyController::class, 'index']);
        Route::get('certification-types', [CertificationTypeController::class, 'index']);
    });

    Route::middleware(CompetencyAccess::class)->group(function () {
        Route::get('competencies/{competency}/courses', [CompetencyController::class, 'courses']);
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
        Route::post('actions/{developmentAction}/evaluate', [DevelopmentActionController::class, 'evaluate']);
        Route::delete('actions/{developmentAction}', [DevelopmentActionController::class, 'destroy']);

        // The authorization register: recommend (who may assess), decide (grant-authorizations, checked inside).
        Route::get('authorization-activities', [AuthorizationActivityController::class, 'index']);
        Route::get('authorizations', [AuthorizationController::class, 'index']);
        Route::get('employees/{employee}/authorization-options', [AuthorizationController::class, 'options']);
        Route::post('authorizations', [AuthorizationController::class, 'store']);
        Route::post('authorizations/{employeeAuthorization}/grant', [AuthorizationController::class, 'grant']);
        Route::post('authorizations/{employeeAuthorization}/decline', [AuthorizationController::class, 'decline']);
        Route::post('authorizations/{employeeAuthorization}/revoke', [AuthorizationController::class, 'revoke']);

        Route::post('evidence', [CompetencyEvidenceController::class, 'store']);
        Route::delete('evidence/{competencyEvidenceFile}', [CompetencyEvidenceController::class, 'destroy']);
    });

    // Certification types: competency or certification managers.
    Route::middleware('permission:manage-competencies|manage-certifications')->group(function () {
        Route::post('certification-types', [CertificationTypeController::class, 'store']);
        Route::put('certification-types/{certificationType}', [CertificationTypeController::class, 'update']);
        Route::delete('certification-types/{certificationType}', [CertificationTypeController::class, 'destroy']);
    });

    Route::middleware('permission:manage-competencies')->group(function () {
        Route::post('competencies', [CompetencyController::class, 'store']);
        Route::put('competencies/{competency}', [CompetencyController::class, 'update']);
        Route::delete('competencies/{competency}', [CompetencyController::class, 'destroy']);
        Route::put('positions/{position}', [PositionCompetencyController::class, 'sync']);
        Route::put('positions/{position}/certifications', [PositionCompetencyController::class, 'syncCertifications']);
        Route::post('authorization-activities', [AuthorizationActivityController::class, 'store']);
        Route::put('authorization-activities/{authorizationActivity}', [AuthorizationActivityController::class, 'update']);
        Route::delete('authorization-activities/{authorizationActivity}', [AuthorizationActivityController::class, 'destroy']);
    });
});
