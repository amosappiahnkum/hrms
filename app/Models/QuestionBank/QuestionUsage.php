<?php

namespace App\Models\QuestionBank;

use Database\Factories\QuestionBank\QuestionUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class QuestionUsage extends Model
{
    /** @use HasFactory<QuestionUsageFactory> */
    use HasFactory;

    protected $fillable = [
        'question_id',
        'usable_type',
        'usable_id',
        'order',
        'custom_weight',
        'is_required_override',
    ];

    protected $casts = [
        'order' => 'integer',
        'custom_weight' => 'decimal:2',
        'is_required_override' => 'boolean',
    ];

    /**
     * Question relationship
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Polymorphic relation to usable models (Appraisal, Quiz, etc.)
     */
    public function usable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get effective weight (custom or default)
     */
    public function getEffectiveWeightAttribute(): float
    {
        return $this->custom_weight ?? $this->question->weight;
    }

    /**
     * Get effective required status
     */
    public function getIsEffectivelyRequiredAttribute(): bool
    {
        return $this->is_required_override ?? $this->question->is_required;
    }
}
