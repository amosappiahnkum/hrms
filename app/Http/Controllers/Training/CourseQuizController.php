<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppraisalTemplateResource;
use App\Models\Appraisal\Assessment;
use App\Models\QuestionBank\Question;
use App\Models\QuestionBank\QuestionUsage;
use App\Models\Training\Course;
use App\Models\Training\CourseChapter;
use App\Models\Training\CourseMaterial;
use App\Http\Resources\Training\CourseMaterialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CourseQuizController extends Controller
{
    /**
     * List all training quizzes for this course.
     * GET /training/courses/{course}/quizzes
     */
    public function index(Course $course): AnonymousResourceCollection
    {
        $quizzes = $course->quizzes()
            ->withCount('questionUsages as questions_count')
            ->latest()
            ->paginate(50);

        return AppraisalTemplateResource::collection($quizzes);
    }

    /**
     * Create a quiz and immediately attach it to a chapter.
     * POST /training/chapters/{chapter}/quiz
     */
    public function createForChapter(Request $request, CourseChapter $courseChapter): JsonResponse
    {
        $request->validate([
            'title'         => ['nullable', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'quiz_required' => ['boolean'],
        ]);

        $course = $courseChapter->course;

        $quiz = Assessment::create([
            'title'           => $request->input('title', "{$courseChapter->title} Quiz"),
            'description'     => $request->description,
            'type'            => 'training',
            'assignable_type' => Course::class,
            'assignable_id'   => $course->id,
            'is_active'       => true,
            'user_id'         => auth()->id(),
        ]);

        $order = $courseChapter->materials()->max('order') + 1;

        $material = CourseMaterial::create([
            'chapter_id'         => $courseChapter->id,
            'title'              => $quiz->title,
            'type'               => CourseMaterial::TYPE_QUIZ,
            'quiz_assessment_id' => $quiz->id,
            'quiz_required'      => $request->boolean('quiz_required', false),
            'order'              => $order,
            'is_downloadable'    => false,
        ]);

        $material->load('quizAssessment');

        activity('training')->performedOn($quiz)
            ->log("Created quiz for chapter: {$courseChapter->title}");

        return ApiResponse::success(CourseMaterialResource::make($material), 'Quiz created and attached.', 201);
    }

    /**
     * Create a new quiz for this course.
     * POST /training/courses/{course}/quizzes
     */
    public function store(Request $request, Course $course): JsonResponse
    {
        $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $quiz = Assessment::create([
            'title'           => $request->title,
            'description'     => $request->description,
            'type'            => 'training',
            'assignable_type' => Course::class,
            'assignable_id'   => $course->id,
            'is_active'       => $request->boolean('is_active', true),
            'user_id'         => auth()->id(),
        ]);

        activity('training')->performedOn($quiz)->log("Created quiz: {$quiz->title} for course: {$course->title}");

        return ApiResponse::success(AppraisalTemplateResource::make($quiz), 'Quiz created.', 201);
    }

    /**
     * Show a single quiz with its questions.
     * GET /training/quizzes/{assessment}
     */
    public function show(Assessment $assessment): JsonResponse
    {
        $this->authorizeTrainingQuiz($assessment);

        $assessment->load(['questionUsages.question.options', 'questionUsages.question.questionCategory']);

        return ApiResponse::success(AppraisalTemplateResource::make($assessment));
    }

    /**
     * Update quiz metadata (title, description, is_active).
     * PUT /training/quizzes/{assessment}
     */
    public function update(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeTrainingQuiz($assessment);

        $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $assessment->update($request->only(['title', 'description', 'is_active']));

        activity('training')->performedOn($assessment)->log("Updated quiz: {$assessment->title}");

        return ApiResponse::success(AppraisalTemplateResource::make($assessment));
    }

    /**
     * Delete a quiz and its question usages.
     * DELETE /training/quizzes/{assessment}
     */
    public function destroy(Assessment $assessment): JsonResponse
    {
        $this->authorizeTrainingQuiz($assessment);

        $title = $assessment->title;

        // Delete any chapter material rows pointing to this quiz
        CourseMaterial::where('quiz_assessment_id', $assessment->id)->delete();

        QuestionUsage::where('usable_type', Assessment::class)
            ->where('usable_id', $assessment->id)
            ->delete();

        $assessment->delete();

        activity('training')->log("Deleted quiz: {$title}");

        return ApiResponse::success([], 'Quiz deleted.');
    }

    /**
     * Replace the full set of questions on a quiz.
     * POST /training/quizzes/{assessment}/questions/sync
     */
    public function syncQuestions(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeTrainingQuiz($assessment);

        $request->validate([
            'questions'                 => ['required', 'array'],
            'questions.*.uuid'          => ['required', 'exists:questions,uuid'],
            'questions.*.order'         => ['nullable', 'integer', 'min:0'],
            'questions.*.custom_weight' => ['nullable', 'numeric', 'min:0'],
        ]);

        QuestionUsage::where('usable_type', Assessment::class)
            ->where('usable_id', $assessment->id)
            ->delete();

        foreach ($request->questions as $index => $item) {
            $question = Question::where('uuid', $item['uuid'])->firstOrFail();
            QuestionUsage::create([
                'question_id'   => $question->id,
                'usable_type'   => Assessment::class,
                'usable_id'     => $assessment->id,
                'order'         => $item['order'] ?? $index,
                'custom_weight' => $item['custom_weight'] ?? null,
                'user_id'       => auth()->id(),
            ]);
        }

        $assessment->load(['questionUsages.question.options', 'questionUsages.question.questionCategory']);

        activity('training')->performedOn($assessment)
            ->withProperties(['question_count' => count($request->questions)])
            ->log("Synced questions on quiz: {$assessment->title}");

        return ApiResponse::success(AppraisalTemplateResource::make($assessment));
    }

    private function authorizeTrainingQuiz(Assessment $assessment): void
    {
        abort_unless(
            $assessment->type === 'training' && $assessment->assignable_type === Course::class,
            403,
            'Not a training quiz.'
        );
    }
}
