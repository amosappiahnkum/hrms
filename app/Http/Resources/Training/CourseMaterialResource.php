<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'                 => $this->uuid,
            'title'                => $this->title,
            'type'                 => $this->type,
            'description'          => $this->description,
            'order'                => $this->order,
            'file_name'            => $this->file_name,
            'file_size'            => $this->file_size,
            'mime_type'            => $this->mime_type,
            'url'                  => $this->url,
            'text_content'         => $this->text_content,
            'duration_seconds'     => $this->duration_seconds,
            'is_downloadable'      => $this->is_downloadable,
            'has_pdf_preview'      => $this->hasPdfPreview(),
            // Quiz-specific fields
            'quiz_assessment_uuid' => $this->when(
                $this->type === 'quiz',
                fn () => $this->whenLoaded(
                    'quizAssessment',
                    fn () => $this->quizAssessment?->uuid,
                    $this->quizAssessment?->uuid
                )
            ),
            'quiz_required'        => $this->when($this->type === 'quiz', fn () => $this->quiz_required),
            // signed_url only when explicitly loaded (self-service /url endpoint)
            'signed_url'           => $this->when(
                $this->resource->relationLoaded('signedUrl'),
                fn () => $this->signed_url
            ),
            'viewed'               => $this->when(
                isset($this->viewed),
                fn () => $this->viewed
            ),
            'created_at'           => $this->created_at,
        ];
    }
}
