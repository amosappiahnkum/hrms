<?php

use App\Http\Controllers\Appraisal\AppraisalTemplateController;
use App\Http\Controllers\Appraisal\AssessmentAttemptController;
use App\Http\Controllers\Appraisal\AssessmentWindowController;
use App\Http\Controllers\JobCategoryController;
use Illuminate\Support\Facades\Route;

Route::get('job-categories', [JobCategoryController::class, 'index']);

// ── Employee-facing: take assessments ────────────────────────────────────────
Route::prefix('my/assessments')->group(function () {
    Route::get('/', [AssessmentAttemptController::class, 'myWindows']);
    Route::post('/{assessmentWindow}/start', [AssessmentAttemptController::class, 'startOrResume']);
    Route::get('/{assessmentWindow}/review', [AssessmentAttemptController::class, 'myAttemptReview']);
    Route::post('/attempts/{attempt}/responses', [AssessmentAttemptController::class, 'saveResponses']);
    Route::post('/attempts/{attempt}/submit', [AssessmentAttemptController::class, 'submit']);
});

Route::middleware('feature:appraisal.enabled')->group(function () {

    // ── Supervisor: review direct reports' appraisals ─────────────────────────
    Route::prefix('appraisal/supervisor')->group(function () {
        Route::get('/pending', [AssessmentAttemptController::class, 'supervisorPending']);
        Route::post('/attempts/{attempt}/confirm', [AssessmentAttemptController::class, 'supervisorConfirm']);
        Route::post('/attempts/{attempt}/return', [AssessmentAttemptController::class, 'supervisorReturn']);
        Route::post('/attempts/{attempt}/responses/override', [AssessmentAttemptController::class, 'supervisorOverrideResponses']);
        Route::post('/attempts/{attempt}/kpis/sync', [AssessmentAttemptController::class, 'syncKpis']);
    });

    // ── HR: finalise confirmed appraisals ─────────────────────────────────────
    Route::prefix('appraisal/hr')->middleware('role:hr|super-admin')->group(function () {
        Route::get('/pending', [AssessmentAttemptController::class, 'hrPending']);
        Route::post('/attempts/{attempt}/complete', [AssessmentAttemptController::class, 'hrComplete']);
    });

    // ── Appraisal Officer: finalise HR employees' appraisals ──────────────────
    Route::prefix('appraisal/appraisal-officer')->middleware('role:appraisal_officer|super-admin')->group(function () {
        Route::get('/pending', [AssessmentAttemptController::class, 'appraisalOfficerPending']);
        Route::post('/attempts/{attempt}/complete', [AssessmentAttemptController::class, 'appraisalOfficerComplete']);
    });

    // ── Admin: assessment templates ───────────────────────────────────────────
    Route::prefix('appraisal/templates')->group(function () {
        Route::get('/', [AppraisalTemplateController::class, 'index']);
        Route::post('/', [AppraisalTemplateController::class, 'store']);
        Route::get('/{assessment}', [AppraisalTemplateController::class, 'show']);
        Route::get('/{assessment}/preview', [AppraisalTemplateController::class, 'preview']);
        Route::put('/{assessment}', [AppraisalTemplateController::class, 'update']);
        Route::delete('/{assessment}', [AppraisalTemplateController::class, 'destroy']);
        Route::post('/{assessment}/questions/sync', [AppraisalTemplateController::class, 'syncQuestions']);
        Route::post('/{assessment}/questions', [AppraisalTemplateController::class, 'addQuestion']);
        Route::delete('/{assessment}/questions/{question}', [AppraisalTemplateController::class, 'removeQuestion']);
    });

    // ── Admin: assessment sessions (windows) ──────────────────────────────────
    Route::prefix('appraisal/windows')->group(function () {
        Route::get('/', [AssessmentWindowController::class, 'index']);
        Route::post('/', [AssessmentWindowController::class, 'store']);
        Route::get('/{assessmentWindow}', [AssessmentWindowController::class, 'show']);
        Route::put('/{assessmentWindow}', [AssessmentWindowController::class, 'update']);
        Route::delete('/{assessmentWindow}', [AssessmentWindowController::class, 'destroy']);
        Route::post('/{assessmentWindow}/open', [AssessmentWindowController::class, 'open']);
        Route::post('/{assessmentWindow}/close', [AssessmentWindowController::class, 'close']);
        Route::get('/{assessmentWindow}/attempts', [AssessmentWindowController::class, 'attempts']);
        Route::get('/{assessmentWindow}/stats', [AssessmentWindowController::class, 'stats']);
    });
});
