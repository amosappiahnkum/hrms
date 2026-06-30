<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Training\CourseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = CourseCategory::orderBy('order')->orderBy('name')->get();

        return ApiResponse::success($categories->map(fn($c) => [
            'id'          => $c->id,
            'uuid'        => $c->uuid,
            'name'        => $c->name,
            'description' => $c->description,
            'order'       => $c->order,
        ])->values());
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => ['required', 'string', 'max:100', 'unique:course_categories,name'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $order = CourseCategory::max('order') + 1;
        $category = CourseCategory::create([
            'name'        => $request->name,
            'description' => $request->description,
            'order'       => $order,
        ]);

        activity('training')->performedOn($category)->log("Created course category: {$category->name}");

        return ApiResponse::success([
            'id'          => $category->id,
            'uuid'        => $category->uuid,
            'name'        => $category->name,
            'description' => $category->description,
            'order'       => $category->order,
        ], 'Category created.', 201);
    }

    public function update(Request $request, CourseCategory $courseCategory): JsonResponse
    {
        $request->validate([
            'name'        => ['sometimes', 'string', 'max:100', "unique:course_categories,name,{$courseCategory->id}"],
            'description' => ['nullable', 'string', 'max:255'],
            'order'       => ['nullable', 'integer', 'min:0'],
        ]);

        $courseCategory->update($request->only(['name', 'description', 'order']));

        activity('training')->performedOn($courseCategory)->log("Updated course category: {$courseCategory->name}");

        return ApiResponse::success([
            'id'          => $courseCategory->id,
            'uuid'        => $courseCategory->uuid,
            'name'        => $courseCategory->name,
            'description' => $courseCategory->description,
            'order'       => $courseCategory->order,
        ]);
    }

    public function destroy(CourseCategory $courseCategory): JsonResponse
    {
        $name = $courseCategory->name;
        $courseCategory->delete();

        activity('training')->log("Deleted course category: {$name}");

        return ApiResponse::success([], 'Category deleted.');
    }
}
