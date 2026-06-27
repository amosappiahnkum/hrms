<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_attempts', function (Blueprint $table) {
            // Weighted average of responses using supervisor overrides where present
            $table->decimal('score', 5, 2)->nullable()->after('supervisor_confirmed_at');
            // Weighted average using only employee's own responses (for gap analysis)
            $table->decimal('self_score', 5, 2)->nullable()->after('score');
            // Average KPI achievement rate: (actual / target) * 100 per KPI, then averaged
            $table->decimal('kpi_score', 5, 2)->nullable()->after('self_score');
            $table->timestamp('finalized_at')->nullable()->after('kpi_score');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->dropColumn(['score', 'self_score', 'kpi_score', 'finalized_at']);
        });
    }
};