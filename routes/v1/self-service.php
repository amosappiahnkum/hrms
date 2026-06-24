<?php

use App\Http\Controllers\CommunityServiceController;
use App\Http\Controllers\SelfService\DependantController;
use App\Http\Controllers\SelfService\EmergencyContactController;
use App\Http\Controllers\SelfService\ExperienceController;
use App\Http\Controllers\SelfService\QualificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:self_service.enabled')->group(function () {
    Route::middleware('feature:self_service.qualifications')
        ->apiResource('/qualifications', QualificationController::class);

    Route::middleware('feature:self_service.experience')
        ->apiResource('/experiences', ExperienceController::class);

    Route::middleware('feature:self_service.emergency_contacts')
        ->apiResource('/emergency-contacts', EmergencyContactController::class);

    Route::middleware('feature:self_service.dependants')
        ->apiResource('/dependants', DependantController::class);

    Route::middleware('feature:self_service.community_services')
        ->apiResource('/community-services', CommunityServiceController::class);
});
