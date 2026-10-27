<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A bank's branch, with the sort code bank upload files ask for. Picked on pay details. */
class BankBranch extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['bank_id', 'name', 'sort_code', 'active', 'merged_into_id'];

    protected $casts = ['active' => 'boolean'];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class)->withTrashed();
    }

    /** This bank's active branch with this name or sort code (case and spaces aside), if there is one. */
    public static function findFor(Bank|int $bank, ?string $text): ?self
    {
        $key = Bank::key($text);

        return $key === '' ? null : self::where('bank_id', $bank instanceof Bank ? $bank->id : $bank)->where('active', true)->get()
            ->first(fn (self $b) => Bank::key($b->name) === $key || ($b->sort_code && Bank::key($b->sort_code) === $key));
    }
}
