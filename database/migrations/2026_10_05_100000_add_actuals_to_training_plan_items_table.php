<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The training record: what actually happened on a planned training (dates, hours, attendance,
 * cost, provider, result). Progress fields, so recording them needs no re-approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_plan_items', function (Blueprint $table) {
            $table->date('actual_start_date')->nullable()->after('planned_end_date');
            $table->date('actual_end_date')->nullable()->after('actual_start_date');
            $table->decimal('hours', 6, 1)->nullable()->after('actual_end_date');
            $table->boolean('attended')->nullable()->after('hours');
            $table->decimal('actual_cost', 12, 2)->nullable()->after('attended');
            $table->string('provider')->nullable()->after('actual_cost');
            $table->decimal('score', 5, 2)->nullable()->after('provider');
            $table->boolean('passed')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('training_plan_items', function (Blueprint $table) {
            $table->dropColumn(['actual_start_date', 'actual_end_date', 'hours', 'attended', 'actual_cost', 'provider', 'score', 'passed']);
        });
    }
};
