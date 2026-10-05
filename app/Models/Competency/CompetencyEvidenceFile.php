<?php

namespace App\Models\Competency;

use App\Models\AppModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file evidencing competence, on a rating or a development action. Removing one hides it from the
 * record; the stored file is kept (it may be carried forward, and records are retained).
 */
class CompetencyEvidenceFile extends AppModel
{
    use SoftDeletes;

    /** What may be uploaded, and how big (KB). */
    public const MIMES = 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx';
    public const MAX_KB = 20480;
    public const MAX_PER_TARGET = 10;

    protected $fillable = ['evidenceable_type', 'evidenceable_id', 'file_path', 'file_name', 'file_size', 'mime_type', 'uploaded_by'];

    protected $casts = ['file_size' => 'integer'];

    public function evidenceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** The same file on another record (carried forward), without copying it in storage. */
    public function copyTo(\Illuminate\Database\Eloquent\Model $target): self
    {
        return $target->evidenceFiles()->create($this->only(['file_path', 'file_name', 'file_size', 'mime_type', 'uploaded_by']));
    }

    public function payload(): array
    {
        return [
            'uuid'        => $this->uuid,
            'file_name'   => $this->file_name,
            'file_size'   => $this->file_size,
            'mime_type'   => $this->mime_type,
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'uploaded_by' => $this->uploader?->name,
        ];
    }
}
