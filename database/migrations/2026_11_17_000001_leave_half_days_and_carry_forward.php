<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Half days (allow_half_day): leave day counts hold halves. Drops leave config columns that duplicated others and
 * were never read (carry_forward_allowed, max_days_per_request, requires_approval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->decimal('days_requested', 6, 1)->change();
            $table->decimal('days_approved', 6, 1)->default(0)->change();
        });
        Schema::table('leave_approvals', function (Blueprint $table) {
            $table->decimal('days_approved', 6, 1)->default(0)->nullable()->change();
        });
        Schema::table('leave_balance_adjustments', function (Blueprint $table) {
            $table->decimal('days', 6, 1)->change();
        });

        Schema::table('leave_type_level_configs', function (Blueprint $table) {
            foreach (['carry_forward_allowed', 'max_days_per_request', 'requires_approval'] as $column) {
                if (Schema::hasColumn('leave_type_level_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_type_level_configs', function (Blueprint $table) {
            $table->integer('max_days_per_request')->nullable();
            $table->boolean('carry_forward_allowed')->default(false);
            $table->boolean('requires_approval')->default(true);
        });
        Schema::table('leave_balance_adjustments', function (Blueprint $table) {
            $table->integer('days')->change();
        });
        Schema::table('leave_approvals', function (Blueprint $table) {
            $table->integer('days_approved')->default(0)->nullable()->change();
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->integer('days_requested')->change();
            $table->integer('days_approved')->default(0)->change();
        });
    }
};
