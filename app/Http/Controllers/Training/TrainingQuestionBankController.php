<?php

namespace App\Http\Controllers\Training;

use App\Enums\QuestionType;
use App\Exports\TrainingQuestionTemplateExport;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use App\Imports\TrainingQuestionImport;
use App\Models\QuestionBank\Question;
use App\Models\QuestionBank\QuestionCategory;
use App\Models\Training\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TrainingQuestionBankController extends Controller
{
    /**
     * Shared filters (search/type/category/active) for both the per-course
     * and cross-course question bank listings.
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Question::with(['questionCategory', 'options', 'dependsOn'])
            ->where('scope', 'training');

        if ($request->filled('search')) {
            $query->where('text', 'like', "%{$request->search}%");
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('category_uuid')) {
            $query->whereHas('questionCategory', fn ($q) =>
                $q->where('uuid', $request->category_uuid)
            );
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $query;
    }

    /**
     * List questions for a course's question bank.
     * GET /training/courses/{course}/questions
     */
    public function index(Request $request, Course $course): AnonymousResourceCollection
    {
        $questions = $this->filteredQuery($request)
            ->where('course_id', $course->id)
            ->orderBy('order')->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return QuestionResource::collection($questions);
    }

    /**
     * List training questions across all courses — the module-wide question bank.
     * GET /training/questions
     */
    public function globalIndex(Request $request): AnonymousResourceCollection
    {
        $query = $this->filteredQuery($request)->with('course');

        if ($request->filled('course_uuid')) {
            $query->whereHas('course', fn ($q) => $q->where('uuid', $request->course_uuid));
        }

        $questions = $query->orderBy('order')->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return QuestionResource::collection($questions);
    }

    /**
     * Download a blank import template.
     * GET /training/courses/{course}/questions/template
     */
    public function templateDownload(Course $course): BinaryFileResponse
    {
        return Excel::download(new TrainingQuestionTemplateExport(), "training-questions-template-{$course->uuid}.xlsx");
    }

    /**
     * Bulk-import questions from an uploaded spreadsheet.
     * POST /training/courses/{course}/questions/import
     */
    public function import(Request $request, Course $course): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ]);

        $import = new TrainingQuestionImport($course);
        Excel::import($import, $request->file('file'));

        return ApiResponse::success([
            'imported' => $import->imported,
            'errors'   => $import->errors,
        ], $import->imported . ' question(s) imported successfully.');
    }

    /**
     * Create a new question, pre-assigned to this course's question bank.
     * POST /training/courses/{course}/questions
     */
    public function store(Request $request, Course $course): JsonResponse
    {
        $question = $this->createQuestion($request, $course->id);

        return ApiResponse::success(QuestionResource::make($question), 'Question created.', 201);
    }

    /**
     * Create a new question in the shared, module-wide bank — optionally
     * assigned to a course up front, or left unassigned to be attached to a
     * quiz later.
     * POST /training/questions
     */
    public function globalStore(Request $request): JsonResponse
    {
        $course = $request->filled('course_uuid')
            ? Course::where('uuid', $request->course_uuid)->firstOrFail()
            : null;

        $question = $this->createQuestion($request, $course?->id);

        return ApiResponse::success(QuestionResource::make($question), 'Question created.', 201);
    }

    private function createQuestion(Request $request, ?int $courseId): Question
    {
        $validated = $request->validate([
            'category_uuid' => ['nullable', 'exists:question_categories,uuid'],
            'type' => ['required', new Enum(QuestionType::class)],
            'text' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],

            // Conditional display — parent question must sit in the same bank (same course, or both unassigned)
            'depends_on_uuid' => ['nullable', Rule::exists('questions', 'uuid')->where(fn ($q) => $this->scopeToCourse($q, $courseId))],
            'show_when_value' => ['nullable', 'array', 'required_with:depends_on_uuid'],
            'show_when_value.*' => ['string', 'max:255'],

            'options' => ['nullable', 'array'],
            'options.*.option_text' => ['required_with:options', 'string', 'max:255'],
            'options.*.option_value' => ['nullable', 'numeric', 'max:255'],
        ]);

        $dependsOnId = !empty($validated['depends_on_uuid'])
            ? $this->scopeToCourse(Question::where('uuid', $validated['depends_on_uuid']), $courseId)->value('id')
            : null;

        $question = Question::create([
            ...Arr::except($validated, ['category_uuid', 'depends_on_uuid']),
            'scope' => 'training',
            'course_id' => $courseId,
            'user_id' => auth()->id(),
            'question_category_id' => $this->resolveCategory($validated['category_uuid'] ?? null),
            'depends_on_question_id' => $dependsOnId,
        ]);

        if (!empty($validated['options'])) {
            foreach ($validated['options'] as $opt) {
                $question->options()->create($opt);
            }
        }

        $question->load(['questionCategory', 'options', 'dependsOn', 'course']);

        return $question;
    }

    /** Constrains a Question query to a specific course, or to unassigned questions when $courseId is null. */
    private function scopeToCourse(Builder $query, ?int $courseId): Builder
    {
        return $courseId ? $query->where('course_id', $courseId) : $query->whereNull('course_id');
    }

    /**
     * Update a course question.
     * PUT /training/questions/{question}
     */
    public function update(Request $request, Question $question): JsonResponse
    {
        abort_unless($question->scope === 'training', 403, 'Not a training question.');

        // Resolve the course this question will belong to after this update, for depends_on scoping
        $targetCourseId = $question->course_id;
        if ($request->has('course_uuid')) {
            $targetCourseId = $request->filled('course_uuid')
                ? Course::where('uuid', $request->course_uuid)->value('id')
                : null;
        }

        $validated = $request->validate([
            'type' => ['sometimes', new Enum(QuestionType::class)],
            'text' => ['sometimes', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
            'category_uuid' => ['nullable', 'exists:question_categories,uuid'],
            'course_uuid' => ['nullable', 'exists:courses,uuid'],

            // Conditional display — parent question must sit in the same bank this question will belong to
            'depends_on_uuid' => ['nullable', Rule::exists('questions', 'uuid')->where(fn ($q) => $this->scopeToCourse($q, $targetCourseId))],
            'show_when_value' => ['nullable', 'array'],
            'show_when_value.*' => ['string', 'max:255'],

            'options' => ['nullable', 'array'],
            'options.*.option_text' => ['required_with:options', 'string', 'max:255'],
            'options.*.option_value' => ['nullable', 'numeric', 'max:255'],
        ]);

        if ($request->filled('category_uuid')) {
            $validated['question_category_id'] = $this->resolveCategory($validated['category_uuid']);
        }

        if ($request->has('course_uuid')) {
            $validated['course_id'] = $targetCourseId;
        }

        if ($request->filled('depends_on_uuid')) {
            $validated['depends_on_question_id'] = $this->scopeToCourse(
                Question::where('uuid', $validated['depends_on_uuid']),
                $targetCourseId
            )->value('id');
        } elseif ($request->has('depends_on_uuid')) {
            // Explicitly cleared — drop the dependency and its trigger values
            $validated['depends_on_question_id'] = null;
            $validated['show_when_value'] = null;
        }

        $question->update(Arr::except($validated, ['category_uuid', 'course_uuid', 'depends_on_uuid']));

        if (array_key_exists('options', $validated)) {
            $question->options()->delete();
            foreach ($validated['options'] ?? [] as $opt) {
                $question->options()->create($opt);
            }
        }

        $question->load(['questionCategory', 'options', 'dependsOn', 'course']);

        return ApiResponse::success(QuestionResource::make($question));
    }

    private function resolveCategory(?string $uuid): int
    {
        if ($uuid) {
            return QuestionCategory::where('uuid', $uuid)->value('id');
        }

        return QuestionCategory::firstOrCreate(
            ['name' => 'Uncategorized'],
            ['uuid' => Str::uuid()],
        )->id;
    }

    /**
     * Delete a course question.
     * DELETE /training/questions/{question}
     */
    public function destroy(Question $question): JsonResponse
    {
        abort_unless($question->scope === 'training', 403, 'Not a training question.');

        $usageCount = $question->usages()->count();
        if ($usageCount > 0) {
            return response()->json([
                'message' => "Cannot delete: this question is used in {$usageCount} quiz(zes).",
            ], 422);
        }

        $question->delete();

        return ApiResponse::success([], 'Question deleted.');
    }
}
