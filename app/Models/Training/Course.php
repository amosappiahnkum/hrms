<?php

namespace App\Models\Training;

use App\Models\Appraisal\Assessment;
use App\Models\ApplicationModel;
use App\Models\QuestionBank\Question;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends ApplicationModel
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'course_category_id',
        'thumbnail_path',
        'thumbnail_url',
        'duration_minutes',
        'final_quiz_id',
        'passing_score',
        'is_published',
        'user_id',
    ];

    protected $casts = [
        'is_published'     => 'boolean',
        'duration_minutes' => 'integer',
        'passing_score'    => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'course_category_id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(CourseChapter::class)->orderBy('order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CourseAssignment::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function finalQuiz(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'final_quiz_id');
    }

    /** All training quiz templates scoped to this course. */
    public function quizzes(): MorphMany
    {
        return $this->morphMany(Assessment::class, 'assignable')
            ->where('type', 'training');
    }

    /** All questions created specifically for this course's quiz bank. */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function isAccessibleBy(User $user): bool
    {
        return $this->assignments()->where(function ($q) use ($user) {
            $q->where('scope_type', 'all')
              ->orWhere(function ($q) use ($user) {
                  $q->where('scope_type', 'employee')
                    ->whereJsonContains('scope_ids', $user->id);
              })
              ->orWhere(function ($q) use ($user) {
                  $employee = $user->employee;
                  if (!$employee) return;
                  $q->where('scope_type', 'department')
                    ->whereJsonContains('scope_ids', $employee->department_id);
              })
              ->orWhere(function ($q) use ($user) {
                  $employee = $user->employee;
                  if (!$employee) return;
                  $q->where('scope_type', 'job_category')
                    ->whereJsonContains('scope_ids', $employee->job_category_id);
              });
        })->exists();
    }
}
