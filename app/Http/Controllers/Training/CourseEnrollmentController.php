<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Training\CourseEnrollmentResource;
use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Training\Course;
use App\Models\Training\CourseEnrollment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CourseEnrollmentController extends Controller
{
    /**
     * List all enrollments (admin-wide or filtered by course).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $enrollments = CourseEnrollment::with(['course', 'user'])
            ->when($request->course_uuid, fn ($q, $uuid) =>
                $q->whereHas('course', fn ($c) => $c->where('uuid', $uuid))
            )
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return CourseEnrollmentResource::collection($enrollments);
    }

    /**
     * Admin manually enrolls one or more users in a course.
     */
    public function store(Request $request, Course $course): JsonResponse
    {
        $request->validate([
            'user_ids'   => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'exists:users,id'],
            'due_date'   => ['nullable', 'date'],
        ]);

        $existingUserIds = $course->enrollments()->pluck('user_id')->flip();
        $toEnroll        = collect($request->user_ids)->reject(fn ($id) => $existingUserIds->has($id));

        $now  = now();
        $rows = $toEnroll->map(fn ($uid) => [
            'course_id'   => $course->id,
            'user_id'     => $uid,
            'status'      => CourseEnrollment::STATUS_ENROLLED,
            'enrolled_at' => $now,
            'due_date'    => $request->due_date,
            'created_at'  => $now,
            'updated_at'  => $now,
        ])->values()->all();

        CourseEnrollment::insert($rows);

        $skipped = count($request->user_ids) - count($toEnroll);

        activity('training')->log(
            "Manually enrolled " . count($toEnroll) . " user(s) in course: {$course->title}"
            . ($skipped ? " ({$skipped} already enrolled, skipped)" : '')
        );

        return ApiResponse::success([
            'enrolled' => count($toEnroll),
            'skipped'  => $skipped,
        ], count($toEnroll) . ' user(s) enrolled.', 201);
    }

    /**
     * Per-question response breakdown for one quiz attempt belonging to an enrollment.
     * GET /training/enrollments/{enrollment}/quiz-results/{assessment}
     */
    public function attemptResponses(CourseEnrollment $courseEnrollment, Assessment $assessment): JsonResponse
    {
        $attempt = AssessmentAttempt::where('course_enrollment_id', $courseEnrollment->id)
            ->where('assessment_id', $assessment->id)
            ->with(['responses.question.options'])
            ->first();

        abort_unless($attempt, 404, 'No attempt found for this enrollment and quiz.');

        $assessment->load(['questionUsages.question.options']);

        $passingScore = $courseEnrollment->course?->passing_score;

        $maxScore = (float) $assessment->questionUsages()
            ->join('questions', 'questions.id', '=', 'question_usages.question_id')
            ->sum(DB::raw('COALESCE(app_question_usages.custom_weight, app_questions.weight, 1)'));

        $responsesByQuestionId = $attempt->responses->keyBy('question_id');

        $questions = $assessment->questionUsages
            ->sortBy('order')
            ->map(function ($usage) use ($responsesByQuestionId) {
                $q        = $usage->question;
                $response = $responsesByQuestionId->get($q->id);
                $weight   = (float) ($usage->custom_weight ?? $q->weight ?? 1);
                $maxVal   = $q->options->isNotEmpty()
                    ? (float) $q->options->max(fn ($o) => (float) ($o->option_value ?? 0))
                    : null;
                $maxQuestionScore = $maxVal !== null ? $maxVal * $weight : null;

                $chosenOption = null;
                if ($response?->answer !== null && $q->options->isNotEmpty()) {
                    $chosenOption = $q->options->firstWhere('option_text', $response->answer);
                }

                $isCorrect = null;
                if ($response && $maxQuestionScore !== null && $maxQuestionScore > 0) {
                    $isCorrect = ((float) ($response->score ?? 0)) >= $maxQuestionScore;
                }

                return [
                    'uuid'             => $q->uuid,
                    'text'             => $q->text,
                    'type'             => $q->type,
                    'order'            => $usage->order,
                    'max_score'        => $maxQuestionScore,
                    'options'          => $q->options->map(fn ($o) => [
                        'uuid'         => $o->uuid,
                        'option_text'  => $o->option_text,
                        'option_value' => $o->option_value,
                    ])->values(),
                    'response'         => $response ? [
                        'answer'            => $response->answer,
                        'chosen_text'       => $chosenOption?->option_text,
                        'score'             => $response->score,
                        'is_correct'        => $isCorrect,
                    ] : null,
                ];
            })
            ->values();

        $scorePct = null;
        $passed   = null;
        if ($attempt->score !== null && $maxScore > 0) {
            $scorePct = round(($attempt->score / $maxScore) * 100, 1);
            if ($passingScore !== null) {
                $passed = $scorePct >= $passingScore;
            }
        }

        return ApiResponse::success([
            'quiz' => [
                'uuid'      => $assessment->uuid,
                'title'     => $assessment->title,
                'max_score' => $maxScore,
            ],
            'attempt' => [
                'uuid'         => $attempt->uuid,
                'status'       => $attempt->status,
                'score'        => $attempt->score,
                'score_pct'    => $scorePct,
                'passed'       => $passed,
                'started_at'   => $attempt->started_at?->toISOString(),
                'submitted_at' => $attempt->submitted_at?->toISOString(),
            ],
            'passing_score' => $passingScore,
            'questions'     => $questions,
        ]);
    }

    /**
     * All quiz results for a single enrolled student.
     * GET /training/enrollments/{enrollment}/quiz-results
     */
    public function quizResults(CourseEnrollment $courseEnrollment): JsonResponse
    {
        $courseEnrollment->load(['course.chapters.materials', 'user']);
        $course = $courseEnrollment->course;

        // Collect all quiz Assessment IDs attached to this course
        // — chapter quizzes (assignable = CourseChapter) and final quiz (assignable = Course)
        $chapterIds = $course->chapters->pluck('id');

        $quizzes = Assessment::where(function ($q) use ($course, $chapterIds) {
            $q->where('assignable_type', Course::class)->where('assignable_id', $course->id)
              ->orWhere(function ($q2) use ($chapterIds) {
                  $q2->where('assignable_type', \App\Models\Training\CourseChapter::class)
                     ->whereIn('assignable_id', $chapterIds);
              });
        })->get();

        $attempts = AssessmentAttempt::where('course_enrollment_id', $courseEnrollment->id)
            ->whereIn('assessment_id', $quizzes->pluck('id'))
            ->get()
            ->keyBy('assessment_id');

        $passingScore = $course->passing_score;

        $results = $quizzes->map(function (Assessment $quiz) use ($attempts, $passingScore) {
            $maxScore = (float) $quiz->questionUsages()
                ->join('questions', 'questions.id', '=', 'question_usages.question_id')
                ->sum(DB::raw('COALESCE(app_question_usages.custom_weight, app_questions.weight, 1)'));

            $attempt = $attempts->get($quiz->id);

            $scorePct = null;
            $passed   = null;
            if ($attempt && $attempt->score !== null && $maxScore > 0) {
                $scorePct = round(($attempt->score / $maxScore) * 100, 1);
                if ($passingScore !== null) {
                    $passed = $scorePct >= $passingScore;
                }
            }

            $isFinal = $quiz->assignable_type === Course::class;

            return [
                'quiz_uuid'  => $quiz->uuid,
                'quiz_title' => $quiz->title ?? ($isFinal ? 'Final Quiz' : 'Quiz'),
                'is_final'   => $isFinal,
                'max_score'  => $maxScore,
                'attempt'    => $attempt ? [
                    'uuid'         => $attempt->uuid,
                    'status'       => $attempt->status,
                    'score'        => $attempt->score,
                    'score_pct'    => $scorePct,
                    'passed'       => $passed,
                    'started_at'   => $attempt->started_at?->toISOString(),
                    'submitted_at' => $attempt->submitted_at?->toISOString(),
                ] : null,
            ];
        })->values();

        return ApiResponse::success([
            'enrollment' => [
                'uuid'         => $courseEnrollment->uuid,
                'status'       => $courseEnrollment->status,
                'enrolled_at'  => $courseEnrollment->enrolled_at?->toISOString(),
                'completed_at' => $courseEnrollment->completed_at?->toISOString(),
                'due_date'     => $courseEnrollment->due_date?->toISOString(),
                'final_score'  => $courseEnrollment->final_score,
            ],
            'user' => [
                'name'  => $courseEnrollment->user->name,
                'email' => $courseEnrollment->user->email,
            ],
            'passing_score' => $passingScore,
            'quizzes'       => $results,
        ]);
    }

    /**
     * Admin unenrolls a user (removes their enrollment + progress).
     */
    public function destroy(CourseEnrollment $courseEnrollment): JsonResponse
    {
        $name   = $courseEnrollment->user?->name ?? "user #{$courseEnrollment->user_id}";
        $course = $courseEnrollment->course?->title ?? "course #{$courseEnrollment->course_id}";

        // Cascades delete chapter_progress, material_views, and linked quiz attempts
        $courseEnrollment->delete();

        activity('training')->log("Unenrolled {$name} from {$course}");

        return ApiResponse::success([], 'Enrollment removed.');
    }
}
