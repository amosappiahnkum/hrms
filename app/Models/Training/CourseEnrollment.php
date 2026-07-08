<?php

namespace App\Models\Training;

use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\ApplicationModel;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class CourseEnrollment extends ApplicationModel
{
    use HasUuid;

    const string STATUS_ENROLLED    = 'enrolled';
    const string STATUS_IN_PROGRESS = 'in_progress';
    const string STATUS_COMPLETED   = 'completed';
    const string STATUS_FAILED      = 'failed';

    protected $fillable = [
        'uuid',
        'course_id',
        'user_id',
        'status',
        'enrolled_at',
        'due_date',
        'completed_at',
        'final_score',
    ];

    protected $casts = [
        'enrolled_at'  => 'datetime',
        'due_date'     => 'datetime',
        'completed_at' => 'datetime',
        'final_score'  => 'decimal:2',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chapterProgress(): HasMany
    {
        return $this->hasMany(ChapterProgress::class, 'enrollment_id');
    }

    public function materialViews(): HasMany
    {
        return $this->hasMany(MaterialView::class, 'enrollment_id');
    }

    public function isChapterComplete(CourseChapter $chapter): bool
    {
        return $this->chapterProgress()
            ->where('chapter_id', $chapter->id)
            ->whereNotNull('completed_at')
            ->exists();
    }

    public function checkCompletion(): bool
    {
        $course = $this->course()->with('chapters')->firstOrFail();

        $allChaptersDone = $course->chapters->every(
            fn ($ch) => $this->isChapterComplete($ch)
        );

        if (!$allChaptersDone) {
            return false;
        }

        // If the course requires a final quiz, it must have been submitted
        if ($course->final_quiz_id) {
            $finalQuizDone = AssessmentAttempt::where('course_enrollment_id', $this->id)
                ->whereNull('course_chapter_id')   // null = final quiz, not a chapter quiz
                ->where('status', AssessmentAttempt::STATUS_SUBMITTED)
                ->exists();

            if (!$finalQuizDone) {
                return false;
            }
        }

        $this->update([
            'status'       => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'final_score'  => $this->computeFinalScore(),
        ]);

        return true;
    }

    /**
     * Overall quiz score as a percentage (0–100) across all submitted attempts.
     * Earned = sum of attempt scores. Max = sum of (max option_value × weight) per question.
     */
    private function computeFinalScore(): ?float
    {
        $attempts = AssessmentAttempt::where('course_enrollment_id', $this->id)
            ->where('status', AssessmentAttempt::STATUS_SUBMITTED)
            ->whereNotNull('score')
            ->pluck('score', 'assessment_id');

        if ($attempts->isEmpty()) {
            return null;
        }

        $assessmentIds = $attempts->keys();

        // Max score per assessment via the polymorphic usable relation
        // (question_usages.usable_type = Assessment::class, usable_id = assessment id)
        $p = DB::getTablePrefix();

        $maxScores = DB::table('question_usages')
            ->join('questions', 'questions.id', '=', 'question_usages.question_id')
            ->join('question_options', 'question_options.question_id', '=', 'questions.id')
            ->where('question_usages.usable_type', Assessment::class)
            ->whereIn('question_usages.usable_id', $assessmentIds)
            ->groupBy(
                'question_usages.usable_id',
                'question_usages.id',
                'question_usages.custom_weight',
                'questions.weight'
            )
            ->select(
                'question_usages.usable_id as assessment_id',
                DB::raw("MAX(CAST({$p}question_options.option_value AS DECIMAL(10,2))) * COALESCE({$p}question_usages.custom_weight, {$p}questions.weight, 1) AS max_q")
            )
            ->get()
            ->groupBy('assessment_id')
            ->map(fn ($rows) => $rows->sum('max_q'));

        $totalEarned = 0.0;
        $totalMax    = 0.0;

        foreach ($attempts as $assessmentId => $score) {
            $max = (float) ($maxScores->get($assessmentId) ?? 0);
            if ($max <= 0) continue;
            $totalEarned += (float) $score;
            $totalMax    += $max;
        }

        return $totalMax > 0 ? round(($totalEarned / $totalMax) * 100, 1) : null;
    }
}
