<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\DocumentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DocumentCategory::withCount(['policyDocuments', 'children'])
            ->orderBy('name');

        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->input('parent_id') ?: null);
        }

        return ApiResponse::success($query->get(['id', 'uuid', 'name', 'description', 'parent_id']));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'parent_id'   => ['nullable', 'exists:document_categories,id'],
        ]);

        $category = DocumentCategory::create($request->only('name', 'description', 'parent_id'));

        activity('document-categories')->performedOn($category)->log("Created document category: {$category->name}");

        return ApiResponse::success(
            $category->loadCount(['policyDocuments', 'children']),
            'Category created.',
            201
        );
    }

    public function update(Request $request, DocumentCategory $documentCategory): JsonResponse
    {
        $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'parent_id'   => ['nullable', 'exists:document_categories,id'],
        ]);

        // Prevent a category from being made a child of its own descendant
        if ($request->filled('parent_id')) {
            $descendantIds = $this->collectDescendantIds($documentCategory);
            if (in_array((int) $request->parent_id, $descendantIds)) {
                return response()->json(['message' => 'A category cannot be moved into one of its own sub-categories.'], 422);
            }
        }

        $documentCategory->update($request->only('name', 'description', 'parent_id'));

        activity('document-categories')->performedOn($documentCategory)->log("Updated document category: {$documentCategory->name}");

        return ApiResponse::success($documentCategory->loadCount(['policyDocuments', 'children']));
    }

    public function destroy(DocumentCategory $documentCategory): JsonResponse
    {
        if ($documentCategory->children()->exists()) {
            return response()->json(['message' => 'Cannot delete a category that has sub-categories.'], 422);
        }

        if ($documentCategory->policyDocuments()->exists()) {
            return response()->json(['message' => 'Cannot delete a category that has documents assigned to it.'], 422);
        }

        $name = $documentCategory->name;
        $documentCategory->delete();

        activity('document-categories')->log("Deleted document category: {$name}");

        return ApiResponse::success([], 'Category deleted.');
    }

    private function collectDescendantIds(DocumentCategory $category): array
    {
        $ids = [];
        foreach ($category->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $this->collectDescendantIds($child));
        }
        return $ids;
    }
}
