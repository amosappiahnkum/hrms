<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's KPI plan: the targets agreed for a period, set ahead of the appraisal (by the supervisor or HR, or as
 * the next period's targets during an appraisal). Copied into the appraisal for that period when it starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_kpis', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->text('description');
            $table->decimal('target', 10, 2);
            $table->unsignedSmallInteger('order')->default(0);
            // The appraisal they were set in, as its next period's targets (null: set on the KPI plan).
            $table->foreignId('source_attempt_id')->nullable()->constrained('assessment_attempts')->nullOnDelete();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_kpis');
    }
};
