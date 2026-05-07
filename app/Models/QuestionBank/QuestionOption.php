<?php

namespace App\Models\QuestionBank;

use Database\Factories\QuestionBank\QuestionOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
    /** @use HasFactory<QuestionOptionFactory> */
    use HasFactory;

    protected $fillable = [
        'question_id',
        'option_text',
        'option_value',
        'order',
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
