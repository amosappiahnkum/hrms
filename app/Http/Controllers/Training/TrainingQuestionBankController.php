<?php

namespace App\Http\Controllers\Training;

use App\Enums\QuestionType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use App\Models\QuestionBank\Question;
use App\Models\Training\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

class TrainingQuestionBankController extends Controller
{
    /**
     * List questions for a course's question bank.
     * GET /training/courses/{course}/questions
     */
    public function index(Request $request, Course $course): AnonymousResourceCollection
    {
        $query = Question::with(['questionCategory', 'options'])
            ->where('course_id', $course->id)
            ->where('scope', 'training');

        if ($request->filled('search')) {
            $query->where('text', 'like', "%{$request->search}%");
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $questions = $query->orderBy('order')->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return QuestionResource::collection($questions);
    }

    /**
     * Create a new question in a course's question bank.
     * POST /training/courses/{course}/questions
     */
    public function store(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', new Enum(QuestionType::class)],
            'text' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
            'options' => ['nullable', 'array'],
            'options.*.option_text' => ['required_with:options', 'string', 'max:255'],
            'options.*.option_value' => ['nullable', 'numeric', 'max:255'],
        ]);

        $question = Question::create([
            ...$validated,
            'scope' => 'training',
            'course_id' => $course->id,
            'user_id' => auth()->id(),
        ]);

        if (!empty($validated['options'])) {
            foreach ($validated['options'] as $opt) {
                $question->options()->create($opt);
            }
        }

        $question->load(['questionCategory', 'options']);

        return ApiResponse::success(QuestionResource::make($question), 'Question created.', 201);
    }

    /**
     * Update a course question.
     * PUT /training/questions/{question}
     */
    public function update(Request $request, Question $question): JsonResponse
    {
        abort_unless($question->scope === 'training' && $question->course_id !== null, 403, 'Not a training question.');

        $validated = $request->validate([
            'type' => ['sometimes', new Enum(QuestionType::class)],
            'text' => ['sometimes', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
            'options' => ['nullable', 'array'],
            'options.*.option_text' => ['required_with:options', 'string', 'max:255'],
            'options.*.option_value' => ['nullable', 'string', 'max:255'],
        ]);

        $question->update($validated);

        if (array_key_exists('options', $validated)) {
            $question->options()->delete();
            foreach ($validated['options'] ?? [] as $opt) {
                $question->options()->create($opt);
            }
        }

        $question->load(['questionCategory', 'options']);

        return ApiResponse::success(QuestionResource::make($question));
    }

    /**
     * Delete a course question.
     * DELETE /training/questions/{question}
     */
    public function destroy(Question $question): JsonResponse
    {
        abort_unless($question->scope === 'training' && $question->course_id !== null, 403, 'Not a training question.');

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
