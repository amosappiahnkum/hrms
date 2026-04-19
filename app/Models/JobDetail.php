<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobDetail extends ApplicationModel
{
    use SoftDeletes, HasUuid;

    /**
     * @var string[]
     */
    protected $fillable = [
        'position_id',
        'status',
        'location',
        'joined_date',
        'contract_start_date',
        'contract_end_date',
        'employee_id',
        'room',
        'user_id',
        'job_category_id',
        'sub_unit_id',
    ];

    protected $casts = [
        'job_category_id' => 'integer',
        'sub_unit_id' => 'integer',
        'employee_id' => 'integer',
        'user_id' => 'integer',
        'joined_date' => 'date',
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
    ];


    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function photo(): MorphOne
    {
        return $this->morphOne(Photo::class, 'photoable');
    }

    public function subUnit(): BelongsTo
    {
        return $this->belongsTo(SubUnit::class)->withDefault([
            'name' => '-'
        ]);
    }

    public function jobCategory(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class)->withDefault([
            'name' => '-'
        ]);
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
