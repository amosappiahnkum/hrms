<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;


class Employee extends ApplicationModel
{
    use HasFactory, SoftDeletes;

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
        'secondment_staff'
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'marital_status' => 'string'
    ];

    /**
     * @return string
     */
    public function getNameAttribute(): string
    {
        return $this->first_name . " " . $this->middle_name . " " . $this->last_name;
    }

    /**
     * @return BelongsTo
     */
    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class)->withDefault(['name' => '-']);
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
        return $this->belongsTo(Department::class)->withDefault(['name' => '-']);
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
    public function departments(): hasMany
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

    public function grantAndFunds()
    {
        return $this->hasMany(GrantAndFund::class);
    }
}
