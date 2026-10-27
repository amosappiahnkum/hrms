<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place work is done (a site code like TC or MV25 on a timesheet); offshore sites allow offshore work. Sites
 * typed in by employees need HR's review (kept, merged into another, or discarded).
 */
class WorkSite extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'is_offshore', 'active', 'needs_review', 'created_by', 'merged_into_id'];

    protected $casts = ['is_offshore' => 'boolean', 'active' => 'boolean', 'needs_review' => 'boolean'];

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
