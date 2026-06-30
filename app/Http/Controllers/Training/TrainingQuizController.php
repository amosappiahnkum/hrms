<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentAttemptResource;
use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Training\Course;
use App\Models\Training\CourseChapter;
use App\Models\Training\CourseEnrollment;
use App\Models\Training\CourseMaterial;
use Illuminate\Http\JsonResponse;

class TrainingQuizController extends Controller
{
    /**
     * Start or resume the quiz for a specific quiz-type material.
     */
    public function startMaterialQuiz(CourseMaterial $courseMaterial): JsonResponse
    {
        abort_unless($courseMaterial->type === CourseMaterial::TYPE_QUIZ, 404, 'Not a quiz material.');
        abort_unless($courseMaterial->quiz_assessment_id, 404, 'Quiz not configured on this material.');

        $userId     = auth()->id();
        $chapter    = $courseMaterial->chapter;
        $enrollment = $this->requireEnrollment($userId, $chapter->course_id);
        $assessment = Assessment::findOrFail($courseMaterial->quiz_assessment_id);

        $attempt = AssessmentAttempt::firstOrCreate(
            [
                'course_enrollment_id' => $enrollment->id,
                'course_chapter_id'    => $chapter->id,
                'assessment_id'        => $assessment->id,
                'user_id'              => $userId,
            ],
            [
                'status'     => AssessmentAttempt::STATUS_DRAFT,
                'started_at' => now(),
            ]
        );

        if (!$attempt->isEditable()) {
            return ApiResponse::success(array_merge(
                $this->buildStartPayload($attempt, $assessment),
                ['already_submitted' => true]
            ));
        }

        return ApiResponse::success($this->buildStartPayload($attempt, $assessment));
    }

    /**
     * Start or resume the final quiz for an entire course.
     * Only available once all chapters are marked complete.
     */
    public function startFinalQuiz(Course $course): JsonResponse
    {
        abort_unless($course->final_quiz_id, 404, 'This course has no final quiz.');

        $userId     = auth()->id();
        $enrollment = $this->requireEnrollment($userId, $course->id);

        // All chapters must be done before the final quiz unlocks
        $course->loadMissing('chapters');
        $allChaptersDone = $course->chapters->every(
            fn ($ch) => $enrollment->isChapterComplete($ch)
        );
        abort_unless($allChaptersDone, 422, 'Complete all chapters before taking the final quiz.');

        $assessment = Assessment::findOrFail($course->final_quiz_id);

        $attempt = AssessmentAttempt::firstOrCreate(
            [
                'course_enrollment_id' => $enrollment->id,
                'course_chapter_id'  => null,   // null = final quiz
                'user_id'              => $userId,
            ],
            [
                'assessment_id' => $assessment->id,
                'status'        => AssessmentAttempt::STATUS_DRAFT,
                'started_at'    => now(),
            ]
        );

        if (!$attempt->isEditable()) {
            return ApiResponse::error('Final quiz already submitted.', null, 422);
        }

        return ApiResponse::success($this->buildStartPayload($attempt, $assessment));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function requireEnrollment(int $userId, int $courseId): CourseEnrollment
    {
        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        abort_unless($enrollment, 403, 'You are not enrolled in this course.');

        return $enrollment;
    }

    private function computeMaxScore(Assessment $assessment): float
    {
        $total = 0.0;
        foreach ($assessment->questionUsages as $usage) {
            $q = $usage->question;
            if ($q->type === 'open_text' || $q->options->isEmpty()) continue;
            $maxVal = $q->options->max(fn ($o) => (float) ($o->option_value ?? 0));
            $weight = (float) ($usage->custom_weight ?? $q->weight ?? 1);
            $total += $maxVal * $weight;
        }
        return round($total, 2);
    }

    private function buildStartPayload(AssessmentAttempt $attempt, Assessment $assessment): array
    {
        $assessment->load(['questionUsages.question.options', 'questionUsages.question.dependsOn']);
        $attempt->load(['responses.question']);

        return [
            'attempt'         => AssessmentAttemptResource::make($attempt),
            'max_score'       => $this->computeMaxScore($assessment),
            'questions'       => $assessment->questionUsages
                ->sortBy('order')
                ->map(fn ($usage) => [
                    'uuid'            => $usage->question->uuid,
                    'text'            => $usage->question->text,
                    'description'     => $usage->question->description,
                    'type'            => $usage->question->type,
                    'is_required'     => $usage->is_required_override ?? $usage->question->is_required,
                    'order'           => $usage->order,
                    'depends_on_uuid' => $usage->question->dependsOn?->uuid,
                    'show_when_value' => $usage->question->show_when_value,
                    'options'         => $usage->question->options->map(fn ($o) => [
                        'uuid'         => $o->uuid,
                        'option_text'  => $o->option_text,
                        'option_value' => $o->option_value,
                        'order'        => $o->order,
                    ]),
                ])
                ->values(),
            'saved_responses' => $attempt->responses->map(fn ($r) => [
                'question_uuid' => $r->question->uuid ?? null,
                'answer'        => $r->answer,
                'score'         => $r->score,
            ]),
        ];
    }
}
