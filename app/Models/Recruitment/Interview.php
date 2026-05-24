<?php

namespace App\Models\Recruitment;

use App\Enums\InterviewOutcome;
use App\Enums\InterviewType;
use App\Models\ApplicationModel;
use App\Models\SelfService\Employee;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Interview extends ApplicationModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'application_id',
        'interviewer_id',
        'scheduled_at',
        'type',
        'location',
        'notes',
        'outcome',
        'feedback',
        'user_id',
    ];

    protected $casts = [
        'type'         => InterviewType::class,
        'outcome'      => InterviewOutcome::class,
        'scheduled_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'interviewer_id');
    }

    public function interviewers(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'interview_interviewers');
    }
}
