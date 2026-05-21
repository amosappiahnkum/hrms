<?php

namespace App\Models\Recruitment;

use App\Enums\ApplicationStatus;
use App\Models\ApplicationModel;
use App\Models\SelfService\Employee;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Application extends ApplicationModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'candidate_id',
        'job_opening_id',
        'status',
        'cover_letter',
        'applied_at',
        'employee_id',
        'user_id',
    ];

    protected $casts = [
        'status'     => ApplicationStatus::class,
        'applied_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }

    public function offer(): HasOne
    {
        return $this->hasOne(JobOffer::class);
    }
}
