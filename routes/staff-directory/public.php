<?php

use App\Http\Controllers\PublicController;
use App\Http\Controllers\QuickEmailController;

Route::middleware('feature:staff_directory.enabled')->prefix('directory')->group(function () {
    Route::get('/', [PublicController::class, 'getEmployees']);
    Route::get('/counts', [PublicController::class, 'getCounts']);
    Route::get('/ranks', [PublicController::class, 'getRanks']);
    Route::get('/departments', [PublicController::class, 'getDepartments']);

    Route::middleware('feature:quick_email.enabled')
        ->post('mail/send', [QuickEmailController::class, 'send']);

    Route::prefix('{employee}')->group(function () {
        Route::get('/', [PublicController::class, 'getEmployee']);
        Route::get('/stats', [PublicController::class, 'getEmployeeStats']);
        Route::get('/specializations', [PublicController::class, 'getSpecializations']);
        Route::get('/research-interests', [PublicController::class, 'getResearchInterests']);

        Route::middleware('feature:staff_directory.qualifications')
            ->get('/qualifications', [PublicController::class, 'getQualifications']);

        Route::middleware('feature:staff_directory.publications')
            ->get('/publications', [PublicController::class, 'getPublications']);

        Route::middleware('feature:staff_directory.experience')->group(function () {
            Route::get('/experiences', [PublicController::class, 'getExperiences']);
            Route::get('/previous-positions', [PublicController::class, 'getPreviousPositions']);
        });

        Route::middleware('feature:staff_directory.awards')
            ->get('/awards', [PublicController::class, 'getAwards']);

        Route::middleware('feature:staff_directory.achievements')
            ->get('/achievements', [PublicController::class, 'getAchievements']);

        Route::middleware('feature:staff_directory.affiliations')
            ->get('/affiliations', [PublicController::class, 'getAffiliations']);

        Route::middleware('feature:staff_directory.grants')
            ->get('/grants', [PublicController::class, 'getGrants']);

        Route::middleware('feature:staff_directory.projects')
            ->get('/projects', [PublicController::class, 'getProjects']);
    });
});
