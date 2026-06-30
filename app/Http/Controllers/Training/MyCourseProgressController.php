<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Training\ChapterProgress;
use App\Models\Training\CourseChapter;
use App\Models\Training\CourseEnrollment;
use App\Models\Training\CourseMaterial;
use App\Models\Training\MaterialView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MyCourseProgressController extends Controller
{
    /** Record that the authenticated user viewed a material.
     *  Also bumps enrollment to in_progress on first interaction.
     */
    public function markViewed(CourseMaterial $courseMaterial): JsonResponse
    {
        $userId = auth()->id();

        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseMaterial->chapter->course_id)
            ->firstOrFail();

        DB::transaction(function () use ($enrollment, $courseMaterial) {
            MaterialView::firstOrCreate([
                'enrollment_id' => $enrollment->id,
                'material_id'   => $courseMaterial->id,
            ], [
                'viewed_at' => now(),
            ]);

            if ($enrollment->status === CourseEnrollment::STATUS_ENROLLED) {
                $enrollment->update(['status' => CourseEnrollment::STATUS_IN_PROGRESS]);
            }
        });

        return ApiResponse::success([], 'Material marked as viewed.');
    }

    /** Mark a chapter as complete.
     *  Enforces quiz gating: if the chapter has a required quiz the attempt
     *  uuid must be supplied and must exist in chapter_progress (set by the quiz submission flow).
     *  After marking, checks if the entire course is now complete.
     */
    public function completeChapter(Request $request, CourseChapter $courseChapter): JsonResponse
    {
        $userId = auth()->id();

        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseChapter->course_id)
            ->firstOrFail();

        // Quiz gating: quiz_required means the chapter quiz must have been submitted
        if ($courseChapter->quiz_required && $courseChapter->hasQuiz()) {
            $hasPassedQuiz = ChapterProgress::where('enrollment_id', $enrollment->id)
                ->where('chapter_id', $courseChapter->id)
                ->whereNotNull('quiz_attempt_id')
                ->exists();

            abort_unless($hasPassedQuiz, 422, 'Complete the chapter quiz before marking this chapter as done.');
        }

        DB::transaction(function () use ($enrollment, $courseChapter) {
            ChapterProgress::updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'chapter_id'    => $courseChapter->id,
                ],
                ['completed_at' => now()]
            );

            $enrollment->refresh();
            $enrollment->checkCompletion();
        });

        return ApiResponse::success([
            'course_completed' => $enrollment->fresh()->status === CourseEnrollment::STATUS_COMPLETED,
        ], 'Chapter marked as complete.');
    }

    /** Generate a short-lived signed URL for a course material file.
     *  Only accessible to enrolled users.
     */
    public function getUrl(CourseMaterial $courseMaterial): JsonResponse
    {
        $userId = auth()->id();

        $enrolled = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseMaterial->chapter->course_id)
            ->exists();

        abort_unless($enrolled, 403, 'You are not enrolled in this course.');
        abort_if($courseMaterial->isExternalLink(), 422, 'This material has no file to stream.');

        $url = $courseMaterial->getSignedUrl(ttlMinutes: 60, forPreview: true);

        abort_unless($url, 404, 'File not found.');

        return ApiResponse::success([
            'url'         => $url,
            'has_preview' => $courseMaterial->hasPdfPreview(),
        ]);
    }
}
