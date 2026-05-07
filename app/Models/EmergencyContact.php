<?php

namespace App\Models;

use App\Traits\HasApprovalUpdates;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmergencyContact extends AppModel
{
    use HasFactory, SoftDeletes, HasUuid, HasApprovalUpdates;

    protected $fillable = [
        'employee_id',
        'name',
        'relationship',
        'phone_number',
        'alt_phone_number',
        'email',
        'user_id',
    ];

    public function approvableFields(): array
    {
        return [
            'employee_id' => ['show_in_diff' => false],
            'name' => ['show_in_diff' => true],
            'relationship' => ['show_in_diff' => true],
            'phone_number' => ['show_in_diff' => true],
            'alt_phone_number' => ['show_in_diff' => true],
            'email' => ['show_in_diff' => true],
            'user_id' => ['show_in_diff' => false],
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
     * @return MorphOne
     */
    public function informationUpdate(): MorphOne
    {
        return $this->morphOne(InformationUpdate::class, 'information')
            ->where('status', 'pending')
            ->latest();
    }
}
