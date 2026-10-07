<?php

namespace App\Support;

/**
 * The currencies payroll accepts (ISO 4217), matching the list the forms offer. Old codes still in
 * use are read as the current one: GHC (the cedi before 2007) is GHS.
 */
class Currencies
{
    public const CODES = [
        'GHS', 'USD', 'EUR', 'GBP', 'NGN', 'XOF', 'XAF', 'SLE', 'LRD', 'GMD', 'GNF', 'ZAR', 'KES', 'UGX', 'TZS',
        'RWF', 'EGP', 'MAD', 'AOA', 'CHF', 'CAD', 'AUD', 'CNY', 'INR', 'JPY', 'AED', 'SAR',
    ];

    /** Retired code => current code. */
    public const ALIASES = ['GHC' => 'GHS', 'SLL' => 'SLE'];

    /** Upper case, with retired codes replaced; null for empty. */
    public static function normalize(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : (self::ALIASES[$code] ?? $code);
    }

    /** Validation: a known code. */
    public static function rule(): \Illuminate\Validation\Rules\In
    {
        return \Illuminate\Validation\Rule::in(self::CODES);
    }
}
