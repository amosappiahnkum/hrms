<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * 1 unit of a currency in the base currency, from a date. `standard` converts everything; `basic` (optional)
 * converts basic salary when the organization uses a separate rate for it.
 */
class ExchangeRate extends AppModel
{
    use SoftDeletes;

    public const STANDARD = 'standard';
    public const BASIC = 'basic';
    public const TYPES = [self::STANDARD, self::BASIC];

    protected $fillable = ['currency', 'rate_type', 'effective_date', 'rate', 'notes', 'created_by'];

    protected $casts = ['effective_date' => 'date', 'rate' => 'decimal:6'];

    /** The rate in force on a date: the latest of that currency and type on or before it. */
    public static function inForceOn(string $currency, Carbon $on, string $type = self::STANDARD): ?self
    {
        return static::where('currency', strtoupper($currency))->where('rate_type', $type)
            ->whereDate('effective_date', '<=', $on)
            ->orderByDesc('effective_date')->orderByDesc('id')->first();
    }

    /**
     * The rate to convert `currency` into the base currency on a date; 1 for the base currency, null when
     * missing. A basic rate falls back to the standard one.
     */
    public static function for(string $currency, Carbon $on, string $type = self::STANDARD): ?float
    {
        if (strtoupper($currency) === strtoupper((string) setting('payroll.base_currency', 'GHS'))) {
            return 1.0;
        }
        $rate = ($type === self::BASIC ? static::inForceOn($currency, $on, self::BASIC) : null) ?? static::inForceOn($currency, $on);

        return $rate ? (float) $rate->rate : null;
    }
}
