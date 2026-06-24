<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Models\QuestionBank\Question;
use App\Models\QuestionBank\QuestionUsage;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Assessment extends ApplicationModel
{
    use HasUuid;

    protected $table = 'assessments';

    protected $fillable = [
        'title',
        'description',
        'type',
        'assignable_type',
        'assignable_id',
        'is_active',
        'user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    public function questionUsages(): HasMany
    {
        return $this->hasMany(QuestionUsage::class, 'usable_id')
            ->where('usable_type', self::class)
            ->with('question.options')
            ->orderBy('order');
    }

    public function addQuestion(Question $question, int $order = 0, ?float $customWeight = null): QuestionUsage
    {
        return QuestionUsage::firstOrCreate(
            [
                'question_id' => $question->id,
                'usable_type' => self::class,
                'usable_id'   => $this->id,
            ],
            [
                'order'         => $order,
                'custom_weight' => $customWeight,
                'user_id'       => auth()->id(),
            ]
        );
    }

    public function removeQuestion(Question $question): void
    {
        QuestionUsage::where([
            'question_id' => $question->id,
            'usable_type' => self::class,
            'usable_id'   => $this->id,
        ])->delete();
    }
}
