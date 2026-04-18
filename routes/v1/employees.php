<?php

use App\Http\Controllers\AchievementController;
use App\Http\Controllers\AffiliationController;
use App\Http\Controllers\AwardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\GrantAndFundController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PublicationController;
use Illuminate\Support\Facades\Route;

Route::prefix('employees')->group(function () {
    /*Specializations*/
    Route::get('/{employee}/specializations', [EmployeeController::class, 'getSpecializations']);
    Route::put('/{employee}/specializations', [EmployeeController::class, 'updateSpecializations']);
    Route::put('/{employee}/remove-specialization', [EmployeeController::class, 'removeSpecialization']);

    /*ResearchInterest*/
    Route::get('/{employee}/research-interests', [EmployeeController::class, 'getResearchInterests']);
    Route::put('/{employee}/research-interests', [EmployeeController::class, 'updateResearchInterests']);
    Route::put('/{employee}/remove-research-interest', [EmployeeController::class, 'removeResearchInterest']);

    Route::post('/update-onboarding', [EmployeeController::class, 'onboardEmployee']);
    Route::get('/search', [EmployeeController::class, 'searchEmployees']);
    Route::post('update-level', [EmployeeController::class, 'updateEmployeeLevel']);
    Route::post('update-job-type', [EmployeeController::class, 'updateEmployeeStatus']);
    Route::get('/directory', [EmployeeController::class, 'getEmployeeDirectory']);
});
Route::apiResource('/employees', EmployeeController::class);
Route::apiResource('/awards', AwardController::class);
Route::apiResource('/achievements', AchievementController::class);
Route::apiResource('/affiliations', AffiliationController::class);
Route::apiResource('/grants', GrantAndFundController::class);
Route::apiResource('projects', ProjectController::class);
Route::get('employees/{employee}/publications', [PublicationController::class, 'getMyPublications']);
Route::apiResource('publications', PublicationController::class);
