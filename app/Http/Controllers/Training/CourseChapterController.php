<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Training\CourseChapterResource;
use App\Models\Training\Course;
use App\Models\Training\CourseChapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseChapterController extends Controller
{
    public function store(Request $request, Course $course): JsonResponse
    {
        $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $order = $course->chapters()->max('order') + 1;

        $chapter = $course->chapters()->create([
            'title'       => $request->title,
            'description' => $request->description,
            'order'       => $order,
        ]);

        activity('training')->performedOn($chapter)->log("Added chapter: {$chapter->title}");

        return ApiResponse::success(CourseChapterResource::make($chapter), 'Chapter added.', 201);
    }

    public function update(Request $request, CourseChapter $courseChapter): JsonResponse
    {
        $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $courseChapter->update($request->only(['title', 'description']));

        activity('training')->performedOn($courseChapter)->log("Updated chapter: {$courseChapter->title}");

        return ApiResponse::success(CourseChapterResource::make($courseChapter));
    }

    public function destroy(CourseChapter $courseChapter): JsonResponse
    {
        $title = $courseChapter->title;

        $courseChapter->delete();

        activity('training')->log("Deleted chapter: {$title}");

        return ApiResponse::success([], 'Chapter deleted.');
    }

    public function reorder(Request $request, Course $course): JsonResponse
    {
        $request->validate([
            'chapters'         => ['required', 'array'],
            'chapters.*.uuid'  => ['required', 'exists:course_chapters,uuid'],
            'chapters.*.order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($request->chapters as $item) {
            CourseChapter::where('uuid', $item['uuid'])
                ->where('course_id', $course->id)
                ->update(['order' => $item['order']]);
        }

        return ApiResponse::success([], 'Chapters reordered.');
    }
}
