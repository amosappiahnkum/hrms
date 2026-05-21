<?php

use App\Http\Controllers\Recruitment\CandidateDocumentController;
use App\Http\Controllers\Recruitment\CandidateExperienceController;
use App\Http\Controllers\Recruitment\CandidateLanguageController;
use App\Http\Controllers\Recruitment\CandidatePortalController;
use App\Http\Controllers\Recruitment\CandidateQualificationController;
use App\Http\Controllers\Recruitment\CandidateSkillController;
use Illuminate\Support\Facades\Route;

// Middleware (auth:candidate + feature:recruitment.enabled) is applied from api.php.
// This file only defines the route structure under the recruitment/portal prefix.

Route::prefix('recruitment/portal')->group(function () {

    // Session
    Route::post('logout', [CandidatePortalController::class, 'logout']);

    // Profile
    Route::get('me', [CandidatePortalController::class, 'me']);
    Route::put('me', [CandidatePortalController::class, 'updateProfile']);

    // Applications
    Route::get('applications', [CandidatePortalController::class, 'myApplications']);
    Route::post('applications', [CandidatePortalController::class, 'apply']);
    Route::get('applications/{application}', [CandidatePortalController::class, 'applicationDetail']);

    // Interviews
    Route::get('interviews', [CandidatePortalController::class, 'myInterviews']);

    // Sub-resources
    Route::apiResource('experiences', CandidateExperienceController::class);
    Route::apiResource('qualifications', CandidateQualificationController::class);
    Route::apiResource('skills', CandidateSkillController::class);
    Route::apiResource('languages', CandidateLanguageController::class);
    Route::apiResource('documents', CandidateDocumentController::class)->except(['update']);
});
