<?php

use App\Http\Controllers\PublicController;
use App\Http\Controllers\QuickEmailController;

Route::prefix('directory')->group(function () {
    Route::get('/', [PublicController::class, 'getEmployees']);
    Route::get('/counts', [PublicController::class, 'getCounts']);
    Route::get('/ranks', [PublicController::class, 'getRanks']);
    Route::get('/departments', [PublicController::class, 'getDepartments']);
    Route::post('mail/send', [QuickEmailController::class, 'send']);
    Route::group(['prefix' => '{employee}'], function () {
        Route::get('/stats', [PublicController::class, 'getEmployeeStats']);
        Route::get('/qualifications', [PublicController::class, 'getQualifications']);
        Route::get('/publications', [PublicController::class, 'getPublications']);
        Route::get('/specializations', [PublicController::class, 'getSpecializations']);
        Route::get('/research-interests', [PublicController::class, 'getResearchInterests']);
        Route::get('/previous-positions', [PublicController::class, 'getPreviousPositions']);
        Route::get('/experiences', [PublicController::class, 'getExperiences']);
        Route::get('/awards', [PublicController::class, 'getAwards']);
        Route::get('/achievements', [PublicController::class, 'getAchievements']);
        Route::get('/affiliations', [PublicController::class, 'getAffiliations']);
        Route::get('/grants', [PublicController::class, 'getGrants']);
        Route::get('/projects', [PublicController::class, 'getProjects']);
        Route::get('/', [PublicController::class, 'getEmployee']);
    });
});
