<?php

use App\Http\Controllers\Recruitment\ApplicationController;
use App\Http\Controllers\Recruitment\CandidateController;
use App\Http\Controllers\Recruitment\InterviewController;
use App\Http\Controllers\Recruitment\JobOfferController;
use App\Http\Controllers\Recruitment\JobOpeningController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:recruitment.enabled')->prefix('recruitment')->group(function () {

    // Job Openings
    Route::apiResource('job-openings', JobOpeningController::class);
    Route::post('job-openings/{jobOpening}/publish', [JobOpeningController::class, 'publish']);
    Route::post('job-openings/{jobOpening}/close', [JobOpeningController::class, 'close']);

    // Candidates
    Route::apiResource('candidates', CandidateController::class);
    Route::get('candidates/{candidate}/experiences', [CandidateController::class, 'experiences']);
    Route::get('candidates/{candidate}/qualifications', [CandidateController::class, 'qualifications']);
    Route::get('candidates/{candidate}/skills', [CandidateController::class, 'skills']);
    Route::get('candidates/{candidate}/languages', [CandidateController::class, 'languages']);
    Route::get('candidates/{candidate}/documents', [CandidateController::class, 'documents']);
    Route::get('candidates/{candidate}/applications', [CandidateController::class, 'applications']);

    // Applications
    Route::apiResource('applications', ApplicationController::class);
    Route::post('applications/{application}/shortlist', [ApplicationController::class, 'shortlist']);
    Route::post('applications/{application}/reject', [ApplicationController::class, 'reject']);
    Route::post('applications/{application}/hire', [ApplicationController::class, 'hire']);

    // Interviews (nested under application for create/list, standalone for detail)
    Route::get('applications/{application}/interviews', [InterviewController::class, 'index']);
    Route::post('applications/{application}/interviews', [InterviewController::class, 'store']);
    Route::get('interviews/{interview}', [InterviewController::class, 'show']);
    Route::put('interviews/{interview}', [InterviewController::class, 'update']);
    Route::delete('interviews/{interview}', [InterviewController::class, 'destroy']);

    // Job Offers (nested under application for create/list, standalone for detail)
    Route::get('applications/{application}/offers', [JobOfferController::class, 'index']);
    Route::post('applications/{application}/offers', [JobOfferController::class, 'store']);
    Route::get('offers/{offer}', [JobOfferController::class, 'show']);
    Route::put('offers/{offer}', [JobOfferController::class, 'update']);
    Route::delete('offers/{offer}', [JobOfferController::class, 'destroy']);
});
