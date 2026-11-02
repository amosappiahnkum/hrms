<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A bank employees are paid through, chosen (not typed) on pay details and payment file layouts. */
class Bank extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'active', 'merged_into_id'];

    protected $casts = ['active' => 'boolean'];

    public function branches(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BankBranch::class)->orderBy('name');
    }

    /** "GCB  bank" and "gcb bank" are the same name. */
    public static function key(?string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $text));
    }

    /** The active bank with this name or code (case and spaces aside), if there is one. */
    public static function findByName(?string $text): ?self
    {
        $key = self::key($text);

        return $key === '' ? null : self::where('active', true)->get()->first(fn (self $b) => self::key($b->name) === $key || ($b->code && self::key($b->code) === $key));
    }
}
