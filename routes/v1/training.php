<?php

use App\Http\Controllers\SelfService\PreviousPositionController;
use App\Http\Controllers\SelfService\PreviousRankController;
use App\Http\Controllers\Training\CourseCategoryController;
use App\Http\Controllers\Training\CourseChapterController;
use App\Http\Controllers\Training\CourseController;
use App\Http\Controllers\Training\CourseEnrollmentController;
use App\Http\Controllers\Training\CourseMaterialController;
use App\Http\Controllers\Training\CourseQuizController;
use App\Http\Controllers\Training\MyCourseController;
use App\Http\Controllers\Training\MyCourseProgressController;
use App\Http\Controllers\Training\TrainingQuestionBankController;
use App\Http\Controllers\Training\TrainingQuizController;
use App\Http\Controllers\Training\TrainingReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:training.enabled')->group(function () {
    Route::middleware('feature:training.previous_ranks')
        ->apiResource('/previous-ranks', PreviousRankController::class);

    Route::middleware('feature:training.previous_positions')
        ->apiResource('/previous-positions', PreviousPositionController::class);

    // ── Course categories ─────────────────────────────────────────────────────
    Route::prefix('training/categories')->group(function () {
        Route::get('/', [CourseCategoryController::class, 'index']);
        Route::post('/', [CourseCategoryController::class, 'store']);
        Route::put('/{courseCategory}', [CourseCategoryController::class, 'update']);
        Route::delete('/{courseCategory}', [CourseCategoryController::class, 'destroy']);
    });

    // ── Admin / Training Officer: course management ───────────────────────────
    Route::prefix('training/courses')->group(function () {
        Route::get('/', [CourseController::class, 'index']);
        Route::post('/', [CourseController::class, 'store']);
        Route::get('/{course}', [CourseController::class, 'show']);
        Route::put('/{course}', [CourseController::class, 'update']);
        Route::delete('/{course}', [CourseController::class, 'destroy']);
        Route::post('/{course}/banner', [CourseController::class, 'uploadBanner']);
        Route::post('/{course}/publish', [CourseController::class, 'publish']);
        Route::post('/{course}/unpublish', [CourseController::class, 'unpublish']);
        Route::post('/{course}/assign', [CourseController::class, 'assign']);
        Route::get('/{course}/enrollments', [CourseController::class, 'enrollments']);

        // Admin enrollment management
        Route::post('/{course}/enrollments', [CourseEnrollmentController::class, 'store']);

        // Chapters
        Route::post('/{course}/chapters', [CourseChapterController::class, 'store']);
        Route::post('/{course}/chapters/reorder', [CourseChapterController::class, 'reorder']);
    });

    Route::prefix('training/chapters')->group(function () {
        Route::put('/{courseChapter}', [CourseChapterController::class, 'update']);
        Route::delete('/{courseChapter}', [CourseChapterController::class, 'destroy']);

        // Materials
        Route::post('/{courseChapter}/materials', [CourseMaterialController::class, 'store']);
        Route::post('/{courseChapter}/materials/reorder', [CourseMaterialController::class, 'reorder']);
    });

    Route::prefix('training/materials')->group(function () {
        Route::put('/{courseMaterial}', [CourseMaterialController::class, 'update']);
        Route::delete('/{courseMaterial}', [CourseMaterialController::class, 'destroy']);
    });

    // Admin: enrollment management
    Route::prefix('training/enrollments')->group(function () {
        Route::get('/', [CourseEnrollmentController::class, 'index']);
        Route::delete('/{courseEnrollment}', [CourseEnrollmentController::class, 'destroy']);
    });

    // ── Chapter quiz — create + attach in one shot ────────────────────────────
    Route::post('training/chapters/{courseChapter}/quiz', [CourseQuizController::class, 'createForChapter']);

    // ── Course quiz bank (admin) ──────────────────────────────────────────────
    Route::prefix('training/courses/{course}/quizzes')->group(function () {
        Route::get('/', [CourseQuizController::class, 'index']);
        Route::post('/', [CourseQuizController::class, 'store']);
    });

    Route::prefix('training/quizzes')->group(function () {
        Route::get('/{assessment}', [CourseQuizController::class, 'show']);
        Route::put('/{assessment}', [CourseQuizController::class, 'update']);
        Route::delete('/{assessment}', [CourseQuizController::class, 'destroy']);
        Route::post('/{assessment}/questions/sync', [CourseQuizController::class, 'syncQuestions']);
    });

    // ── Course question bank (admin) ──────────────────────────────────────────
    Route::prefix('training/courses/{course}/questions')->group(function () {
        Route::get('/', [TrainingQuestionBankController::class, 'index']);
        Route::post('/', [TrainingQuestionBankController::class, 'store']);
    });

    Route::prefix('training/questions')->group(function () {
        Route::put('/{question}', [TrainingQuestionBankController::class, 'update']);
        Route::delete('/{question}', [TrainingQuestionBankController::class, 'destroy']);
    });

    // ── Reports ───────────────────────────────────────────────────────────────
    Route::prefix('training/reports')->group(function () {
        Route::get('/overview', [TrainingReportController::class, 'overview']);
        Route::get('/courses', [TrainingReportController::class, 'courseStats']);
        Route::get('/transcripts', [TrainingReportController::class, 'enrollmentTranscripts']);
    });

    // ── Employee self-service: enrolled courses ───────────────────────────────
    Route::prefix('my/training')->group(function () {
        Route::get('/courses', [MyCourseController::class, 'index']);
        Route::get('/courses/{course}', [MyCourseController::class, 'show']);

        // Progress tracking
        Route::post('/materials/{courseMaterial}/viewed', [MyCourseProgressController::class, 'markViewed']);
        Route::post('/chapters/{courseChapter}/complete', [MyCourseProgressController::class, 'completeChapter']);
        Route::get('/materials/{courseMaterial}/url', [MyCourseProgressController::class, 'getUrl']);

        // Quiz attempts (responses + submit reuse existing appraisal attempt endpoints)
        Route::post('/materials/{courseMaterial}/quiz/start', [TrainingQuizController::class, 'startMaterialQuiz']);
        Route::post('/courses/{course}/quiz/start', [TrainingQuizController::class, 'startFinalQuiz']);
    });
});
