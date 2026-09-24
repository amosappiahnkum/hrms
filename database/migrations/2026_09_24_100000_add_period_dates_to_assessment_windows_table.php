<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_windows', function (Blueprint $table) {
            // The period being appraised (e.g. Jan–Dec 2025), distinct from when the window is open
            $table->date('period_start')->nullable()->after('end_date');
            $table->date('period_end')->nullable()->after('period_start');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_windows', function (Blueprint $table) {
            $table->dropColumn(['period_start', 'period_end']);
        });
    }
};
