<?php

namespace App\Http\Controllers\QuestionBank;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionCategoryRequest;
use App\Http\Resources\QuestionCategoryResource;
use App\Models\QuestionBank\QuestionCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionCategoryController extends Controller
{
    /**
     * Display a listing of categories
     */
    public function index(Request $request)
    {
        $categories = QuestionCategory::query()
            ->with(['parent', 'children'])
            ->latest()
            ->paginate();


        return QuestionCategoryResource::collection($categories);
    }

    /**
     * Store a newly created category
     */
    public function store(StoreQuestionCategoryRequest $request): JsonResponse
    {
        $category = QuestionCategory::create($request->validated());

        return ApiResponse::success(
            QuestionCategoryResource::make($category),
            'Question Category created successfully.',
            201
        );
    }

    /**
     * Display the specified category
     */
    public function show(QuestionCategory $questionCategory): JsonResponse
    {
        $questionCategory->load(['parent', 'children']);
        return ApiResponse::success(
            QuestionCategoryResource::make($questionCategory),
            'Category'
        );
    }

    /**
     * Update the specified category
     */
    public function update(StoreQuestionCategoryRequest $request, QuestionCategory $category): JsonResponse
    {
        $category->update($request->validated());

        return ApiResponse::success(QuestionCategoryResource::make($category));
    }

    /**
     * Remove the specified category
     */
    public function destroy(QuestionCategory $category): JsonResponse
    {
        // Check if category has questions
        if ($category->questions()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category with existing questions.',
            ], 422);
        }

        // Check if category has children
        if ($category->children()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category with sub-categories.',
            ], 422);
        }

        $category->delete();

        return ApiResponse::success([], 'Category deleted successfully');
    }
}
