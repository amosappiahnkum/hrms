<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One change the appraiser made to an appraisal answer: what stood before, what it became, why, by whom and when. */
class QuestionResponseRevision extends ApplicationModel
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'question_response_id',
        'assessment_attempt_id',
        'previous_answer',
        'previous_score',
        'answer',
        'score',
        'justification',
        'revised_by',
    ];

    protected $casts = [
        'previous_score' => 'integer',
        'score'          => 'integer',
    ];

    public function response(): BelongsTo
    {
        return $this->belongsTo(QuestionResponse::class, 'question_response_id');
    }

    public function reviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }

    public function toSummary(): array
    {
        return [
            'uuid'            => $this->uuid,
            'previous_answer' => $this->previous_answer,
            'previous_score'  => $this->previous_score,
            'answer'          => $this->answer,
            'score'           => $this->score,
            'justification'   => $this->justification,
            'revised_by'      => $this->reviser ? ['uuid' => $this->reviser->uuid, 'name' => $this->reviser->name] : null,
            'created_at'      => $this->created_at?->toDateTimeString(),
        ];
    }
}
