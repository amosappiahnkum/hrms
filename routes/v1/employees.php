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
    // ── Any staff member ──────────────────────────────────────────────────────
    Route::get('/people', [EmployeeController::class, 'getPeople']);
    Route::get('/my-colleagues', [EmployeeController::class, 'getMyTeam']);
    Route::get('/org-colleagues', [EmployeeController::class, 'getEmployeeDirectory']);
    Route::get('employees/search', [EmployeeController::class, 'searchEmployees']);

    // ── HR administration (permission-gated) ──────────────────────────────────
    Route::middleware('permission:view-employee')
        ->get('employee-analytics', [EmployeeAnalyticsController::class, 'index']);

    Route::middleware(['feature:employees.termination', 'permission:terminate-employee'])
        ->post('/terminate-employee', [EmployeeController::class, 'terminateEmployee']);

    Route::middleware('permission:manage-employee-accounts')->group(function () {
        Route::get('search-staff-id', [EmployeeController::class, 'getStaff']);
        Route::post('update-mail', [EmployeeController::class, 'updateStaffMail']);
        Route::post('employees/{employee}/create-account', [EmployeeController::class, 'createAccount']);
        Route::post('employees/{employee}/reset-password', [EmployeeController::class, 'resetPassword']);
    });

    Route::middleware('permission:edit-employee')->group(function () {
        Route::post('employees/update-level', [EmployeeController::class, 'updateEmployeeLevel']);
        Route::post('employees/update-job-type', [EmployeeController::class, 'updateEmployeeStatus']);
    });

    Route::middleware('permission:delete-employee')
        ->post('employees/{uuid}/restore', [EmployeeController::class, 'restore']);

    // index also serves ?export=1, which index() checks against export-employee.
    Route::apiResource('/employees', EmployeeController::class)
        ->only(['index', 'store', 'destroy'])
        ->middlewareFor('index', 'permission:view-employee')
        ->middlewareFor('store', 'permission:add-employee')
        ->middlewareFor('destroy', 'permission:delete-employee');

    // ── The employee themselves, or HR ────────────────────────────────────────
    Route::middleware('owns.employee')->group(function () {
        Route::middleware('feature:employees.photo_upload')
            ->post('upload-photo', [EmployeeController::class, 'uploadPhoto']);

        Route::prefix('employees')->group(function () {
            Route::post('/update-onboarding', [EmployeeController::class, 'onboardEmployee']);

            Route::get('/{employee}/contact', [ContactDetailController::class, 'show']);
            Route::put('/{employee}/contact', [ContactDetailController::class, 'update']);

            Route::get('/{employee}/stats', [EmployeeController::class, 'employeeStats']);
            Route::get('/{employee}/profile-completion', [EmployeeController::class, 'profileCompletion']);

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

        Route::apiResource('/employees', EmployeeController::class)->only(['show', 'update']);
    });
});

// Self-service resource collections (gated by module + sub-feature, scoped to the owner)
Route::middleware(['feature:self_service.enabled', 'owns.employee'])->group(function () {
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
