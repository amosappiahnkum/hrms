<?php

use App\Http\Controllers\ContactDetailController;
use App\Http\Controllers\EmployeeAnalyticsController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\SelfService\AchievementController;
use App\Http\Controllers\SelfService\AffiliationController;
use App\Http\Controllers\SelfService\AwardController;
use App\Http\Controllers\SelfService\GrantAndFundController;
use App\Http\Controllers\SelfService\JobDetailController;
use App\Http\Controllers\SelfService\NextOfKinController;
use App\Http\Controllers\SelfService\ProjectController;
use App\Http\Controllers\SelfService\PublicationController;
use Illuminate\Support\Facades\Route;

// Core employee resource
Route::middleware('feature:employees.enabled')->group(function () {
    Route::get('employee-analytics', [EmployeeAnalyticsController::class, 'index']);
    Route::get('/people', [EmployeeController::class, 'getPeople']);
    Route::get('search-staff-id', [EmployeeController::class, 'getStaff']);
    Route::post('update-mail', [EmployeeController::class, 'updateStaffMail']);
    Route::middleware('feature:employees.termination')
        ->post('/terminate-employee', [EmployeeController::class, 'terminateEmployee']);
    Route::middleware('feature:employees.photo_upload')
        ->post('upload-photo', [EmployeeController::class, 'uploadPhoto']);

    Route::get('/my-colleagues', [EmployeeController::class, 'getMyTeam']);
    Route::get('/org-colleagues', [EmployeeController::class, 'getEmployeeDirectory']);

    Route::prefix('employees')->group(function () {
        Route::get('/search', [EmployeeController::class, 'searchEmployees']);
        Route::post('/update-onboarding', [EmployeeController::class, 'onboardEmployee']);
        Route::post('update-level', [EmployeeController::class, 'updateEmployeeLevel']);
        Route::post('update-job-type', [EmployeeController::class, 'updateEmployeeStatus']);

        Route::get('/{employee}/contact', [ContactDetailController::class, 'show']);
        Route::put('/{employee}/contact', [ContactDetailController::class, 'update']);

        Route::get('/{employee}/stats', [EmployeeController::class, 'employeeStats']);

        Route::get('/{employee}/job-detail', [JobDetailController::class, 'show']);
        Route::put('/{employee}/job-detail', [JobDetailController::class, 'update']);

        Route::middleware('feature:employees.biography')->group(function () {
            Route::get('/{employee}/biography', [EmployeeController::class, 'getBiography']);
            Route::put('/{employee}/biography', [EmployeeController::class, 'updateBiography']);
        });

        Route::middleware('feature:employees.specializations')->group(function () {
            Route::get('/{employee}/specializations', [EmployeeController::class, 'getSpecializations']);
            Route::put('/{employee}/specializations', [EmployeeController::class, 'updateSpecializations']);
            Route::put('/{employee}/remove-specialization', [EmployeeController::class, 'removeSpecialization']);
        });

        Route::middleware('feature:employees.research_interests')->group(function () {
            Route::get('/{employee}/research-interests', [EmployeeController::class, 'getResearchInterests']);
            Route::put('/{employee}/research-interests', [EmployeeController::class, 'updateResearchInterests']);
            Route::put('/{employee}/remove-research-interest', [EmployeeController::class, 'removeResearchInterest']);
        });

        // Self-service sections on the employee profile
        Route::middleware('feature:self_service.enabled')->group(function () {
            Route::middleware('feature:self_service.next_of_kin')->group(function () {
                Route::get('/{employee}/next-of-kin', [NextOfKinController::class, 'show']);
                Route::put('/{employee}/next-of-kin', [NextOfKinController::class, 'update']);
            });
        });
    });
    Route::apiResource('/employees', EmployeeController::class);
});

// Self-service resource collections (gated by module + sub-feature)
Route::middleware('feature:self_service.enabled')->group(function () {
    Route::middleware('feature:self_service.awards')
        ->apiResource('/awards', AwardController::class);

    Route::middleware('feature:self_service.achievements')
        ->apiResource('/achievements', AchievementController::class);

    Route::middleware('feature:self_service.affiliations')
        ->apiResource('/affiliations', AffiliationController::class);

    Route::middleware('feature:self_service.grants')
        ->apiResource('/grants', GrantAndFundController::class);

    Route::middleware('feature:self_service.projects')
        ->apiResource('projects', ProjectController::class);

    Route::middleware('feature:self_service.publications')
        ->apiResource('publications', PublicationController::class);
});
