<?php

namespace App\Models\QuestionBank;

use App\Enums\QuestionType;
use App\Models\ApplicationModel;
use App\Models\Training\Course;
use App\Traits\HasUserId;
use App\Traits\HasUuid;
use Database\Factories\QuestionBank\QuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends ApplicationModel
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory, HasUuid, HasUserId;

    protected $fillable = [
        'question_category_id',
        'uuid',
        'scope',
        'course_id',
        'type',
        'text',
        'description',
        'weight',
        'is_required',
        'is_active',
        'order',
        'depends_on_question_id',
        'show_when_value',
        'user_id',
    ];

    protected $casts = [
        'type'             => QuestionType::class,
        'weight'           => 'decimal:2',
        'is_required'      => 'boolean',
        'is_active'        => 'boolean',
        'order'            => 'integer',
        'show_when_value'  => 'array',
        'course_id'        => 'integer',
    ];

    protected $with = ['options'];

    public function questionCategory(): BelongsTo
    {
        return $this->belongsTo(QuestionCategory::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** The question this one depends on (nullable). */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'depends_on_question_id');
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
        return $query->where('question_category_id', $categoryId);
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
