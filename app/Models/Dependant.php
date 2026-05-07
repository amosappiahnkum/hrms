<?php

namespace App\Models;

use App\Traits\HasApprovalUpdates;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dependant extends AppModel
{
    use HasFactory, SoftDeletes, HasUuid, HasApprovalUpdates;

    protected $fillable = [
        'name',
        'employee_id',
        'relationship',
        'phone_number',
        'alt_phone_number',
        'dob',
        'user_id',
    ];

    public function approvableFields(): array
    {
        return [
            'name' => ['show_in_diff' => true],
            'employee_id' => ['show_in_diff' => false],
            'relationship' => ['show_in_diff' => true],
            'phone_number' => ['show_in_diff' => true],
            'alt_phone_number' => ['show_in_diff' => true],
            'dob' => ['show_in_diff' => true],
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
