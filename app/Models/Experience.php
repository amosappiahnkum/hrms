<?php

namespace App\Models;

use App\Traits\HasApprovalUpdates;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Experience extends AppModel
{
    use HasFactory, SoftDeletes, HasUuid, HasApprovalUpdates;

    protected $fillable = [
        'employee_id',
        'company',
        'job_title',
        'from',
        'to',
        'comment',
        'city',
        'country',
        'job_type',
        'user_id',
    ];

    public function approvableFields(): array
    {
        return [
            'employee_id' => ['show_in_diff' => false],
            'company' => ['show_in_diff' => true],
            'job_title' => ['show_in_diff' => true],
            'from' => ['show_in_diff' => true],
            'to' => ['show_in_diff' => true],
            'comment' => ['show_in_diff' => true],
            'city' => ['show_in_diff' => true],
            'country' => ['show_in_diff' => true],
            'job_type' => ['show_in_diff' => true],
            'user_id' => ['show_in_diff' => false],
        ];
    }

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
