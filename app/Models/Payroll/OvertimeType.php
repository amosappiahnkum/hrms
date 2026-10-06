<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A kind of overtime the organization pays (weekday, weekend, holiday, offshore…), priced by a pay component. */
class OvertimeType extends AppModel
{
    use SoftDeletes;

    public const APPLIES_ON = ['weekday' => 'Weekdays', 'weekend' => 'Weekends', 'holiday' => 'Public holidays', 'any' => 'Any day'];

    protected $fillable = ['name', 'pay_component_id', 'applies_on', 'active', 'sort_order'];

    protected $casts = ['active' => 'boolean', 'sort_order' => 'integer'];

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
