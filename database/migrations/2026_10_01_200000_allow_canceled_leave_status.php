<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling leave writes "canceled" (App\Enums\Statuses::CANCELED), but the status column only
 * allowed "cancelled", so every cancellation failed. Allow the spelling the code uses and move any
 * old rows over to it.
 */
return new class extends Migration
{
    private const STATUSES = ['pending', 'hod_approved', 'hod_rejected', 'moved', 'hr_approved', 'hr_rejected'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $table = DB::getTablePrefix() . 'leave_requests';
        $this->setStatuses($table, [...self::STATUSES, 'cancelled', 'canceled']);
        DB::table('leave_requests')->where('status', 'cancelled')->update(['status' => 'canceled']);
        $this->setStatuses($table, [...self::STATUSES, 'canceled']);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $table = DB::getTablePrefix() . 'leave_requests';
        $this->setStatuses($table, [...self::STATUSES, 'cancelled', 'canceled']);
        DB::table('leave_requests')->where('status', 'canceled')->update(['status' => 'cancelled']);
        $this->setStatuses($table, [...self::STATUSES, 'cancelled']);
    }

    private function setStatuses(string $table, array $values): void
    {
        $list = implode(',', array_map(fn ($v) => "'{$v}'", $values));
        DB::statement("ALTER TABLE `{$table}` MODIFY `status` ENUM({$list}) NOT NULL DEFAULT 'pending'");
    }
};
