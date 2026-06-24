<?php

namespace App\Models\QuestionBank;

use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Database\Factories\QuestionBank\QuestionOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends ApplicationModel
{
    /** @use HasFactory<QuestionOptionFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'question_id',
        'option_text',
        'option_value',
        'order',
        'user_id',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    /**
     * Question relationship
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
