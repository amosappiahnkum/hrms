<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appraisal_kpis', function (Blueprint $table) {
            // current = targets for the period under review (scored);
            // next = targets set for the next appraisal period (not scored)
            $table->string('period', 10)->default('current')->after('assessment_attempt_id');
            $table->index(['assessment_attempt_id', 'period']);
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->boolean('include_next_period_targets')->default(false)->after('include_training_section');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('include_next_period_targets');
        });

        Schema::table('appraisal_kpis', function (Blueprint $table) {
            $table->dropIndex(['assessment_attempt_id', 'period']);
            $table->dropColumn('period');
        });
    }
};
