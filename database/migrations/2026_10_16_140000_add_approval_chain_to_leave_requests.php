<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave approved by more than one head: the heads in order (the supervisor first), fixed when the leave is requested,
 * and whose turn it is. `supervisor_id` stays the head the request is with now. Each head's decision records its step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->json('approval_chain')->nullable()->after('supervisor_id');
            $table->unsignedTinyInteger('approval_step')->nullable()->after('approval_chain');
        });

        // One decision per head: the step it was taken at (0 = the supervisor). The new unique index is added first,
        // since the foreign key on leave_request_id needs an index while the old one goes.
        Schema::table('leave_approvals', function (Blueprint $table) {
            $table->unsignedTinyInteger('step')->default(0)->after('role');
            $table->unique(['leave_request_id', 'role', 'step'], 'leave_approvals_request_role_step_unique');
        });
        Schema::table('leave_approvals', fn (Blueprint $table) => $table->dropUnique(['leave_request_id', 'role']));
    }

    public function down(): void
    {
        // Later heads' decisions don't fit the old one-per-role index.
        \Illuminate\Support\Facades\DB::table('leave_approvals')->where('step', '>', 0)->delete();
        Schema::table('leave_approvals', fn (Blueprint $table) => $table->unique(['leave_request_id', 'role']));
        Schema::table('leave_approvals', function (Blueprint $table) {
            $table->dropUnique('leave_approvals_request_role_step_unique');
            $table->dropColumn('step');
        });
        Schema::table('leave_requests', fn (Blueprint $table) => $table->dropColumn(['approval_chain', 'approval_step']));
    }
};
