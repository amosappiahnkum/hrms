<?php

namespace App\Models\Recruitment;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Candidate extends Authenticatable
{
    use HasFactory, SoftDeletes, HasUuid, Notifiable, LogsActivity;

    protected $appends = ['name'];

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'source',
        'cv_path',
        'password',
        'user_id',
        'date_of_birth',
        'gender',
        'nationality',
        'country',
        'region',
        'address',
        'summary',
        'salary_expectation',
        'salary_currency',
        'linkedin_url',
        'portfolio_url',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'date_of_birth'      => 'date',
        'salary_expectation' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(CandidateExperience::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(CandidateQualification::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(CandidateSkill::class);
    }

    public function languages(): HasMany
    {
        return $this->hasMany(CandidateLanguage::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class);
    }

    /**
     * Compute profile completion state.
     * Relations must already be loaded (or will be lazy-loaded once).
     */
    public function profileCompletion(): array
    {
        $this->loadMissing(['experiences', 'qualifications', 'skills', 'documents']);

        $sections = [
            [
                'key'      => 'basic_info',
                'label'    => 'Basic Information',
                'hint'     => 'Add phone, gender, date of birth and a short summary.',
                'complete' => filled($this->phone) && filled($this->gender)
                              && filled($this->date_of_birth) && filled($this->summary),
                'weight'   => 25,
                'required' => true,
            ],
            [
                'key'      => 'experience',
                'label'    => 'Work Experience',
                'hint'     => 'Add at least one work experience.',
                'complete' => $this->experiences->isNotEmpty(),
                'weight'   => 25,
                'required' => true,
            ],
            [
                'key'      => 'education',
                'label'    => 'Education',
                'hint'     => 'Add at least one qualification.',
                'complete' => $this->qualifications->isNotEmpty(),
                'weight'   => 20,
                'required' => true,
            ],
            [
                'key'      => 'skills',
                'label'    => 'Skills',
                'hint'     => 'Add at least one skill.',
                'complete' => $this->skills->isNotEmpty(),
                'weight'   => 20,
                'required' => true,
            ],
            [
                'key'      => 'documents',
                'label'    => 'Documents / CV',
                'hint'     => 'Upload your CV or other supporting documents.',
                'complete' => $this->documents->isNotEmpty(),
                'weight'   => 10,
                'required' => false,
            ],
        ];

        $percent  = collect($sections)->filter(fn($s) => $s['complete'])->sum('weight');
        $canApply = collect($sections)->filter(fn($s) => $s['required'])->every(fn($s) => $s['complete']);

        return [
            'percent'   => $percent,
            'can_apply' => $canApply,
            'sections'  => $sections,
        ];
    }
}
