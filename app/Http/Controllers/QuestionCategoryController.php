<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuestionCategoryRequest;
use App\Http\Requests\UpdateQuestionCategoryRequest;
use App\Models\QuestionBank\QuestionCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionCategoryController extends Controller
{
    /**
     * Display a listing of categories
     */
    public function index(Request $request): JsonResponse
    {
        $query = QuestionCategory::withCount('questions');

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Include children if requested
        if ($request->boolean('with_children')) {
            $query->with('children');
        }

        // Root categories only
        if ($request->boolean('roots_only')) {
            $query->whereNull('parent_id');
        }

        $categories = $query->orderBy('name')->get();

        return response()->json(['data' => $categories]);
    }

    /**
     * Store a newly created category
     */
    public function store(StoreQuestionCategoryRequest $request): JsonResponse
    {
        $category = QuestionCategory::create($request->validated());

        return response()->json([
            'message' => 'Category created successfully',
            'data' => $category,
        ], 201);
    }

    /**
     * Display the specified category
     */
    public function show(QuestionCategory $category): JsonResponse
    {
        return response()->json([
            'data' => $category->load(['parent', 'children', 'questions']),
        ]);
    }

    /**
     * Update the specified category
     */
    public function update(StoreQuestionCategoryRequest $request, QuestionCategory $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json([
            'message' => 'Category updated successfully',
            'data' => $category,
        ]);
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

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }
}
