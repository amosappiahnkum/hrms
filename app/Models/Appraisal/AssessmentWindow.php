<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Models\QuestionBank\QuestionUsage;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentWindow extends ApplicationModel
{
    use HasUuid;

    protected $fillable = [
        'assessment_id',
        'title',
        'description',
        'start_date',
        'end_date',
        'status',
        'user_id',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date'   => 'datetime',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }

    public function myAttempt(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class)
            ->where('user_id', auth()->id());
    }

    /**
     * Questions snapshotted into this specific session.
     * Populated when the window is opened; independent of template edits after that point.
     */
    public function questionUsages(): HasMany
    {
        return $this->hasMany(QuestionUsage::class, 'usable_id')
            ->where('usable_type', self::class)
            ->with('question.options')
            ->orderBy('order');
    }

    /**
     * Copy questions from the template into this window.
     * Called once when the window transitions to 'open'. After this point
     * editing the template has no effect on this session.
     */
    public function snapshotQuestionsFromTemplate(): void
    {
        // Only snapshot if not already done
        if ($this->questionUsages()->exists()) {
            return;
        }

        $this->assessment->load('questionUsages');

        foreach ($this->assessment->questionUsages as $usage) {
            QuestionUsage::create([
                'question_id'          => $usage->question_id,
                'usable_type'          => self::class,
                'usable_id'            => $this->id,
                'order'                => $usage->order,
                'custom_weight'        => $usage->custom_weight,
                'is_required_override' => $usage->is_required_override,
                'user_id'              => auth()->id() ?? $this->user_id,
            ]);
        }
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function hasActiveAttempts(): bool
    {
        return $this->attempts()->exists();
    }
}
