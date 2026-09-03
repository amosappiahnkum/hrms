<?php

namespace App\Models\SelfService;

use App\Models\AppModel;
use App\Models\Config\Department;
use App\Models\EmployeeCertification;
use App\Models\EmployeeSupervisor;
use App\Models\InformationUpdate;
use App\Models\JobDetail;
use App\Models\LeaveRequest;
use App\Models\Photo;
use App\Models\TerminationReason;
use App\Models\Training\PreviousPosition;
use App\Models\User;
use App\Scopes\EmployeeScope;
use App\Traits\HasApprovalUpdates;
use App\Traits\HasDynamicFields;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;


class Employee extends AppModel
{
    use SoftDeletes, HasUuid, EmployeeScope, HasApprovalUpdates, HasDynamicFields;

    /**
     * @var string[]
     */
    protected $appends = [
        'name'
    ];


    /**
     * @var string[]
     */
    protected $fillable = [
        'title',
        'first_name',
        'middle_name',
        'last_name',
        'staff_id',
        'job_title',
        'job_type',
        'dob',
        'gender',
        'marital_status',
        'telephone',
        'work_telephone',
        'work_email',
        'other_email',
        'ssnit_number',
        'gtec_placement',
        'qualification',
        'rank_id',
        'department_id',
        'user_id',
        'senior_staff',
        'senior_member',
        'junior_staff',
        'secondment_staff',
        "current_status",
        "level",
        "termination_reason_id",
        "termination_date",
        "terminated_by",
        "photo",
        "onboarding",
        "bio",
        "research_interests",
        "specializations",
    ];

    public function approvableFields(): array
    {
        return [
            'title' => ['show_in_diff' => true],
            'first_name' => ['show_in_diff' => true],
            'middle_name' => ['show_in_diff' => true],
            'last_name' => ['show_in_diff' => true],
            'staff_id' => ['show_in_diff' => true],
            'job_type' => ['show_in_diff' => true],
            'dob' => ['show_in_diff' => true],
            'gender' => ['show_in_diff' => true],
            'marital_status' => ['show_in_diff' => true],
            'ssnit_number' => ['show_in_diff' => true],
            'department_id' => ['show_in_diff' => false],
            'rank_id' => ['show_in_diff' => false],
        ];
    }

    protected $attributes = [
        'specializations' => '[]',
        'research_interests' => '[]',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'marital_status' => 'string',
        'specializations' => 'array',
        'research_interests' => 'array',
    ];

    /**
     * @return string
     */
    public function getNameAttribute(): string
    {
        return $this->first_name . " " . $this->middle_name . " " . $this->last_name;
    }

    public function terminationReason(): BelongsTo
    {
        return $this->belongsTo(TerminationReason::class);
    }

    /**
     * @return BelongsTo
     */
    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    /**
     * @return BelongsTo
     */
    public function gtecPlacement(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'gtec_placement')->withDefault(['name' => '-']);
    }

    /**
     * @return BelongsTo
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return MorphOne
     */
    public function photo(): MorphOne
    {
        return $this->morphOne(Photo::class, 'photoable');
    }

    /**
     * @return HasOne
     */
    public function contactDetail(): HasOne
    {
        return $this->hasOne(ContactDetail::class);
    }

    /**
     * @return HasOne
     */
    public function jobDetail(): HasOne
    {
        return $this->hasOne(JobDetail::class);
    }

    /**
     * @return HasOne
     */
    public function nextOfKin(): HasOne
    {
        return $this->hasOne(NextOfKin::class);
    }

    /**
     * @return HasMany
     */
    public function emergencyContacts(): hasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }


    /**
     * @return HasMany
     */
    public function dependants(): hasMany
    {
        return $this->hasMany(Dependant::class);
    }

    /**
     * @return HasMany
     */
    public function qualifications(): HasMany
    {
        return $this->hasMany(Education::class);
    }

    public function latestQualification()
    {
        return $this->hasOne(Education::class)->latestOfMany('date');
    }

    public function latestPosition()
    {
        return $this->hasOne(PreviousPosition::class)->latestOfMany('start');
    }

    /**
     * @return HasOne
     */
    public function userAccount(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * @return HasMany
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * @return HasOne
     */
    public function employeeSupervisor(): HasOne
    {
        return $this->hasOne(EmployeeSupervisor::class);
    }

    /**
     * @return HasMany
     */
    public function supervisorLeaveApprovals(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'supervisor_id');
    }


    /**
     * @return HasMany
     */
    public function hrLeaveApprovals(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'hr_id');
    }

    /**
     * @return MorphOne
     */
    public function informationUpdate(): MorphOne
    {
        return $this->morphOne(InformationUpdate::class, 'information')
            ->where('status', 'pending')
            ->latest();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function publications()
    {
        return $this->hasMany(Publication::class);
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(Experience::class);
    }

    public function previousPositions(): HasMany
    {
        return $this->hasMany(PreviousPosition::class);
    }

    public function grantAndFunds()
    {
        return $this->hasMany(GrantAndFund::class);
    }

    public function awards()
    {
        return $this->hasMany(Award::class);
    }

    public function achievements()
    {
        return $this->hasMany(Achievement::class);
    }

    public function affiliations()
    {
        return $this->hasMany(Affiliation::class);
    }

    public function highestQualification()
    {
        return $this->hasOne(Education::class)->ofMany('education_level_rank', 'max');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class);
    }
}
