<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** 1 unit of a currency in the base currency, for a year. */
class ExchangeRate extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['currency', 'year', 'rate', 'notes', 'created_by'];

    protected $casts = ['year' => 'integer', 'rate' => 'decimal:6'];

    /** The rate to convert `currency` into the base currency in `year`; 1 for the base currency, null when missing. */
    public static function for(string $currency, int $year): ?float
    {
        if (strtoupper($currency) === strtoupper((string) setting('payroll.base_currency', 'GHS'))) {
            return 1.0;
        }

        $rate = static::where('currency', strtoupper($currency))->where('year', $year)->value('rate');

        return $rate === null ? null : (float) $rate;
    }
}
