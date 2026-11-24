<?php

namespace App\Models;

use App\Traits\RecordsActivity;
use App\Models\SelfService\Employee;
use App\Models\SelfService\GrantAndFund;
use App\Models\Training\CourseEnrollment;
use App\Services\Employee\WorkEmail;
use App\Traits\HasActivityLogs;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable, SoftDeletes, HasRoles, HasActivityLogs, HasUuid, RecordsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'phone_number',
        'provider',
        'provider_id',
        'employee_id',
        'password_changed',
        'last_login_at',
    ];

    /** In-app notifications, using a model that fills the required uuid column. */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(static function (User $user) {
            if (!$user->isDirty('email')) {
                return;
            }

            $user->email = WorkEmail::normalize($user->email);

            // A username that was the old email is the login name people know: it follows.
            $old = $user->getOriginal('email');
            if ($user->exists && $old && WorkEmail::normalize($user->getOriginal('username')) === WorkEmail::normalize($old)) {
                $user->username = $user->email;
            }
        });

        // An employee's email is their work email: keep their contact details in step however this was saved.
        static::saved(static function (User $user) {
            if ($user->wasChanged('email') || $user->wasRecentlyCreated) {
                WorkEmail::syncFromUser($user);
            }
        });
    }

    /**
     * @return BelongsTo
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withDefault(null);
    }

    public function grantAndFunds()
    {
        return $this->hasMany(GrantAndFund::class);
    }

    public function courseEnrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }
}
