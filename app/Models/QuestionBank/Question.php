<?php

namespace App\Models\QuestionBank;

use App\Enums\QuestionType;
use Database\Factories\QuestionBank\QuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'type',
        'text',
        'description',
        'weight',
        'is_required',
        'is_active',
        'order',
    ];

    protected $casts = [
        'type' => QuestionType::class,
        'weight' => 'decimal:2',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    protected $with = ['options'];

    /**
     * Category relationship
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(QuestionCategory::class);
    }

    /**
     * Question options (for multiple choice, rating, yes/no)
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('order');
    }

    /**
     * Polymorphic relation to appraisals
     */
   /* public function appraisals(): MorphToMany
    {
        return $this->morphedByMany(
            Appraisal::class,
            'usable',
            'question_usages'
        )->withPivot(['order', 'custom_weight', 'is_required_override'])
            ->withTimestamps();
    }*/

    /**
     * Polymorphic relation to quizzes
     */
    /*public function quizzes(): MorphToMany
    {
        return $this->morphedByMany(
            Quiz::class,
            'usable',
            'question_usages'
        )->withPivot(['order', 'custom_weight', 'is_required_override'])
            ->withTimestamps();
    }*/

    /**
     * All usages of this question
     */
    public function usages(): HasMany
    {
        return $this->hasMany(QuestionUsage::class);
    }

    /**
     * Scope: Active questions only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: By type
     */
    public function scopeOfType($query, QuestionType $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: By category
     */
    public function scopeInCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Check if question requires options
     */
    public function requiresOptions(): bool
    {
        return $this->type->requiresOptions();
    }

    /**
     * Boot method to handle cascading deletes
     */
    protected static function booted(): void
    {
        static::deleting(function ($question) {
            $question->options()->delete();
            $question->usages()->delete();
        });
    }
}
