<?php

namespace App\Models\SelfService;

use App\Models\ApplicationModel;
use App\Models\InformationUpdate;
use App\Traits\HasApprovalUpdates;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;

class ContactDetail extends ApplicationModel
{
    use HasFactory, Notifiable, SoftDeletes, HasUuid, HasApprovalUpdates;

    protected $casts = [
        'social_links' => 'array',
    ];

    protected $fillable = [
        'employee_id',
        'address',
        'city',
        'region',
        'zip_code',
        'country',
        'telephone',
        'work_telephone',
        'work_email',
        'other_email',
        'nationality',
        'user_id',
    ];

    public function approvableFields(): array
    {
        return [
            'employee_id' => ['show_in_diff' => false],
            'address' => ['show_in_diff' => true],
            'city' => ['show_in_diff' => true],
            'region' => ['show_in_diff' => true],
            'country' => ['show_in_diff' => true],
            'telephone' => ['show_in_diff' => true],
            'work_telephone' => ['show_in_diff' => true],
            'work_email' => ['show_in_diff' => true],
            'other_email' => ['show_in_diff' => true],
            'nationality' => ['show_in_diff' => true]
        ];
    }

    /**
     * @return BelongsTo
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @param $notification
     *
     * return string
     */
    public function routeNotificationForMail($notification): string
    {
        return $this->work_email;
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


    public function afterApproval(array $changes): void
    {
        if (array_key_exists('telephone', $changes)) {
//            $phone = app(YourService::class)->cleanPhoneNumber($changes['telephone']);
//            Helper::updateSRMS($this->employee->staff_id, $phone);
        }
    }
}
