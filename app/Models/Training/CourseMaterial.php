<?php

namespace App\Models\Training;

use App\Models\Appraisal\Assessment;
use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class CourseMaterial extends ApplicationModel
{
    use HasUuid;

    const string TYPE_DOCUMENT   = 'document';
    const string TYPE_VIDEO      = 'video';
    const string TYPE_AUDIO      = 'audio';
    const string TYPE_VIDEO_LINK = 'video_link';
    const string TYPE_TEXT       = 'text';
    const string TYPE_QUIZ       = 'quiz';

    protected $fillable = [
        'chapter_id',
        'title',
        'type',
        'description',
        'order',
        'file_path',
        'preview_path',
        'file_name',
        'file_size',
        'mime_type',
        'url',
        'text_content',
        'duration_seconds',
        'is_downloadable',
        'quiz_assessment_id',
        'quiz_required',
    ];

    protected $casts = [
        'order'               => 'integer',
        'file_size'           => 'integer',
        'duration_seconds'    => 'integer',
        'is_downloadable'     => 'boolean',
        'quiz_assessment_id'  => 'integer',
        'quiz_required'       => 'boolean',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(CourseChapter::class, 'chapter_id');
    }

    public function quizAssessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'quiz_assessment_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(MaterialView::class, 'material_id');
    }

    public function isExternalLink(): bool
    {
        return $this->type === self::TYPE_VIDEO_LINK;
    }

    public function getSignedUrl(int $ttlMinutes = 60, bool $forPreview = false): ?string
    {
        // For preview: use the Gotenberg-converted PDF when available
        if ($forPreview && $this->preview_path) {
            return Storage::disk('s3')->temporaryUrl(
                $this->preview_path,
                now()->addMinutes($ttlMinutes),
                [
                    'ResponseContentDisposition' => 'inline',
                    'ResponseContentType'        => 'application/pdf',
                ]
            );
        }

        if (!$this->file_path) {
            return null;
        }

        $disposition = $this->is_downloadable ? 'attachment' : 'inline';

        return Storage::disk('s3')->temporaryUrl(
            $this->file_path,
            now()->addMinutes($ttlMinutes),
            [
                'ResponseContentDisposition' => "{$disposition}; filename=\"{$this->file_name}\"",
                'ResponseContentType'        => $this->mime_type ?? 'application/octet-stream',
            ]
        );
    }

    public function hasPdfPreview(): bool
    {
        return $this->type === self::TYPE_DOCUMENT && $this->preview_path !== null;
    }
}
