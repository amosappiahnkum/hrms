<?php

namespace App\Models\Training;

use App\Models\Appraisal\AssessmentAttempt;
use App\Models\ApplicationModel;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        ]);

        return true;
    }
}
