<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Experience extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

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
    ];

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
