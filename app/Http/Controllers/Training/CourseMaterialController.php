<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Training\CourseMaterialResource;
use App\Models\Appraisal\Assessment;
use App\Models\QuestionBank\QuestionUsage;
use App\Models\Training\CourseChapter;
use App\Models\Training\CourseMaterial;
use App\Services\GotenbergService;
use App\Services\MinioUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CourseMaterialController extends Controller
{
    public function store(Request $request, CourseChapter $courseChapter): JsonResponse
    {
        $request->validate([
            'title'            => ['required', 'string', 'max:255'],
            'type'             => ['required', 'in:document,video,audio,video_link,text'],
            'description'      => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'is_downloadable'  => ['boolean'],
            'file'             => [
                'required_if:type,document,video,audio',
                'file',
                'max:524288',
            ],
            'url'              => ['required_if:type,video_link', 'nullable', 'url', 'max:2048'],
            'text_content'     => ['required_if:type,text', 'nullable', 'string'],
        ]);

        $order = $courseChapter->materials()->max('order') + 1;

        $attributes = [
            'chapter_id'       => $courseChapter->id,
            'title'            => $request->title,
            'type'             => $request->type,
            'description'      => $request->description,
            'order'            => $order,
            'duration_seconds' => $request->duration_seconds,
            'is_downloadable'  => $request->boolean('is_downloadable', true),
            'url'              => $request->type === CourseMaterial::TYPE_VIDEO_LINK ? $request->url : null,
            'text_content'     => $request->type === CourseMaterial::TYPE_TEXT ? $request->text_content : null,
        ];

        if ($request->hasFile('file')) {
            $file     = $request->file('file');
            $minio    = app(MinioUploadService::class);
            $uploaded = $minio->upload($file, null, 'course-materials');

            $previewPath = null;
            if ($request->type === CourseMaterial::TYPE_DOCUMENT
                && !str_contains($file->getMimeType() ?? '', 'pdf')
            ) {
                try {
                    $previewPath = app(GotenbergService::class)->convertToPdf(
                        $file->get(),
                        $file->getClientOriginalName(),
                        $uploaded['path'],
                    );
                } catch (\Throwable $e) {
                    Log::warning('Gotenberg conversion failed for ' . $file->getClientOriginalName() . ': ' . $e->getMessage());
                }
            }

            $attributes = array_merge($attributes, [
                'file_path'    => $uploaded['path'],
                'preview_path' => $previewPath,
                'file_name'    => $file->getClientOriginalName(),
                'file_size'    => $file->getSize(),
                'mime_type'    => $file->getMimeType(),
            ]);
        }

        $material = CourseMaterial::create($attributes);

        activity('training')->performedOn($material)->log("Added material: {$material->title}");

        return ApiResponse::success(CourseMaterialResource::make($material), 'Material added.', 201);
    }

    public function update(Request $request, CourseMaterial $courseMaterial): JsonResponse
    {
        $request->validate([
            'title'            => ['sometimes', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'is_downloadable'  => ['boolean'],
            'url'              => ['nullable', 'url', 'max:2048'],
            'text_content'     => ['nullable', 'string'],
            'quiz_required'    => ['boolean'],
        ]);

        $courseMaterial->update($request->only([
            'title', 'description', 'duration_seconds', 'is_downloadable', 'url', 'text_content', 'quiz_required',
        ]));

        activity('training')->performedOn($courseMaterial)->log("Updated material: {$courseMaterial->title}");

        return ApiResponse::success(CourseMaterialResource::make($courseMaterial));
    }

    public function destroy(CourseMaterial $courseMaterial): JsonResponse
    {
        $title = $courseMaterial->title;

        // For quiz materials, delete the backing Assessment and its question usages
        if ($courseMaterial->type === CourseMaterial::TYPE_QUIZ && $courseMaterial->quiz_assessment_id) {
            QuestionUsage::where('usable_type', Assessment::class)
                ->where('usable_id', $courseMaterial->quiz_assessment_id)
                ->delete();
            // nullOnDelete() will null quiz_assessment_id on this row when the assessment is deleted
            Assessment::find($courseMaterial->quiz_assessment_id)?->delete();
        }

        $minio = app(MinioUploadService::class);

        if ($courseMaterial->file_path) {
            $minio->delete($courseMaterial->file_path);
        }

        if ($courseMaterial->preview_path) {
            $minio->delete($courseMaterial->preview_path);
        }

        $courseMaterial->delete();

        activity('training')->log("Deleted material: {$title}");

        return ApiResponse::success([], 'Material deleted.');
    }

    public function reorder(Request $request, CourseChapter $courseChapter): JsonResponse
    {
        $request->validate([
            'materials'         => ['required', 'array'],
            'materials.*.uuid'  => ['required', 'exists:course_materials,uuid'],
            'materials.*.order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($request->materials as $item) {
            CourseMaterial::where('uuid', $item['uuid'])
                ->where('chapter_id', $courseChapter->id)
                ->update(['order' => $item['order']]);
        }

        return ApiResponse::success([], 'Materials reordered.');
    }
}
