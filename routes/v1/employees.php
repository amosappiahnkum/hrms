<?php

use App\Http\Controllers\ContactDetailController;
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

Route::prefix('employees')->group(function () {
    /*Specializations*/
    Route::get('/{employee}/specializations', [EmployeeController::class, 'getSpecializations']);
    Route::put('/{employee}/specializations', [EmployeeController::class, 'updateSpecializations']);
    Route::put('/{employee}/remove-specialization', [EmployeeController::class, 'removeSpecialization']);

    Route::get('/{employee}/biography', [EmployeeController::class, 'getBiography']);
    Route::put('/{employee}/biography', [EmployeeController::class, 'updateBiography']);

    Route::get('/{employee}/next-of-kin', [NextOfKinController::class, 'show']);
    Route::put('/{employee}/next-of-kin', [NextOfKinController::class, 'update']);

    Route::get('/{employee}/contact', [ContactDetailController::class, 'show']);
    Route::put('/{employee}/contact', [ContactDetailController::class, 'update']);

    Route::get('/{employee}/job-detail', [JobDetailController::class, 'show']);
    Route::put('/{employee}/job-detail', [JobDetailController::class, 'update']);

    /*ResearchInterest*/
    Route::get('/{employee}/research-interests', [EmployeeController::class, 'getResearchInterests']);
    Route::put('/{employee}/research-interests', [EmployeeController::class, 'updateResearchInterests']);
    Route::put('/{employee}/remove-research-interest', [EmployeeController::class, 'removeResearchInterest']);

    Route::post('/update-onboarding', [EmployeeController::class, 'onboardEmployee']);
    Route::get('/search', [EmployeeController::class, 'searchEmployees']);
    Route::post('update-level', [EmployeeController::class, 'updateEmployeeLevel']);
    Route::post('update-job-type', [EmployeeController::class, 'updateEmployeeStatus']);
});

Route::get('/my-colleagues', [EmployeeController::class, 'getMyTeam']);

Route::apiResource('/employees', EmployeeController::class);
Route::apiResource('/awards', AwardController::class);
Route::apiResource('/achievements', AchievementController::class);
Route::apiResource('/affiliations', AffiliationController::class);
Route::apiResource('/grants', GrantAndFundController::class);
Route::apiResource('projects', ProjectController::class);
Route::apiResource('publications', PublicationController::class);
