<?php

namespace App\Models\QuestionBank;

use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Database\Factories\QuestionBank\QuestionUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class QuestionUsage extends ApplicationModel
{
    /** @use HasFactory<QuestionUsageFactory> */
    use HasFactory, HasUuid;

    // Appraisals: who answers the question, the employee (self-assessment) or their supervisor.
    const string RESPONDENT_EMPLOYEE = 'employee';
    const string RESPONDENT_SUPERVISOR = 'supervisor';

    protected $fillable = [
        'uuid',
        'question_id',
        'usable_type',
        'usable_id',
        'order',
        'custom_weight',
        'is_required_override',
        'respondent',
        'user_id',
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

    public function isForSupervisor(): bool
    {
        return $this->respondent === self::RESPONDENT_SUPERVISOR;
    }

    /** The question as a form shows it (needs question.options; category and dependsOn when loaded). */
    public function toQuestionPayload(): array
    {
        $question = $this->question;

        return [
            'uuid'            => $question->uuid,
            'text'            => $question->text,
            'description'     => $question->description,
            'type'            => $question->type,
            'respondent'      => $this->respondent ?? self::RESPONDENT_EMPLOYEE,
            'is_required'     => $this->is_required_override ?? $question->is_required,
            'order'           => $this->order,
            'depends_on_uuid' => $question->relationLoaded('dependsOn') ? $question->dependsOn?->uuid : null,
            'show_when_value' => $question->show_when_value,
            'category'        => $question->relationLoaded('questionCategory') && $question->questionCategory
                ? ['uuid' => $question->questionCategory->uuid, 'name' => $question->questionCategory->name]
                : null,
            'options'         => $question->options->map(fn ($o) => [
                'uuid'         => $o->uuid,
                'option_text'  => $o->option_text,
                'option_value' => $o->option_value,
                'order'        => $o->order,
            ]),
        ];
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
