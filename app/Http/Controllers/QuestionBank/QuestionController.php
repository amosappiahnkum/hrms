<?php

namespace App\Http\Controllers\QuestionBank;

use App\Exports\QuestionTemplateExport;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Imports\QuestionImport;
use App\Models\QuestionBank\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QuestionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Question::with(['questionCategory', 'options', 'dependsOn']);

        // Filter by category — frontend sends UUID, resolve to PK
        if ($request->filled('category_id')) {
            $query->whereHas('questionCategory', fn ($q) =>
                $q->where('uuid', $request->category_id)
            );
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('text', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $questions = $query->orderBy('order')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return QuestionResource::collection($questions);
    }

    /**
     * Store a newly created question
     */
    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $question = Question::create($request->only([
            'question_category_id',
            'type',
            'text',
            'description',
            'weight',
            'is_required',
            'is_active',
            'order',
            'depends_on_question_id',
            'show_when_value',
        ]));

        // Create options if provided
        if ($request->has('options')) {
            foreach ($request->options as $optionData) {
                $question->options()->create($optionData);
            }
        }

        $question->load(['questionCategory', 'options']);
        return ApiResponse::success(QuestionResource::make($question));
    }

    /**
     * Display the specified question
     */
    public function show(Question $question): JsonResponse
    {
        $question->load(['questionCategory', 'options']);
        return ApiResponse::success(QuestionResource::make($question));
    }

    /**
     * Update the specified question
     */
    public function update(UpdateQuestionRequest $request, Question $question): JsonResponse
    {
        $question->update($request->only([
            'question_category_id',
            'type',
            'text',
            'description',
            'weight',
            'is_required',
            'is_active',
            'order',
            'depends_on_question_id',
            'show_when_value',
        ]));

        // Update options
        if ($request->has('options')) {
            // Delete existing options
            $question->options()->delete();

            // Create new options
            foreach ($request->options as $optionData) {
                $question->options()->create($optionData);
            }
        }

        return ApiResponse::success(QuestionResource::make($question));
    }

    /**
     * Remove the specified question
     */
    public function destroy(Question $question): JsonResponse
    {
        // Check if question is in use
        $usageCount = $question->usages()->count();

        if ($usageCount > 0) {
            return response()->json([
                'message' => "Cannot delete question. It is being used in {$usageCount} appraisal(s) or quiz(zes).",
                'can_delete' => false,
                'usage_count' => $usageCount,
            ], 422);
        }

        $question->delete();

        return response()->json([
            'message' => 'Question deleted successfully',
        ]);
    }

    /**
     * Bulk update question order
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'questions' => 'required|array',
            'questions.*.id' => 'required|exists:questions,id',
            'questions.*.order' => 'required|integer|min:0',
        ]);

        foreach ($request->questions as $item) {
            Question::where('id', $item['id'])->update(['order' => $item['order']]);
        }

        return response()->json([
            'message' => 'Questions reordered successfully',
        ]);
    }

    public function templateDownload(): BinaryFileResponse
    {
        return Excel::download(new QuestionTemplateExport(), 'question-bank-template.xlsx');
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ]);

        $import = new QuestionImport();
        Excel::import($import, $request->file('file'));

        return ApiResponse::success([
            'imported' => $import->imported,
            'errors'   => $import->errors,
        ], $import->imported . ' question(s) imported successfully.');
    }

    /**
     * Toggle question active status
     */
    public function toggleActive(Question $question): JsonResponse
    {
        $question->update(['is_active' => !$question->is_active]);

        return response()->json([
            'message' => 'Question status updated successfully',
            'data' => $question,
        ]);
    }
}
