<?php

namespace App\Models;

use App\Models\QuestionBank\Question;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class QuestionResponse extends ApplicationModel
{
    use HasUuid;

    protected $fillable = [
        'question_id',
        'user_id',
        'respondable_type',
        'respondable_id',
        'answer',
        'score',
    ];

    protected $casts = [
        'score' => 'integer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function respondable(): MorphTo
    {
        return $this->morphTo();
    }
}
