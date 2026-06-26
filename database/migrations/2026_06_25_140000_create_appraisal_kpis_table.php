<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appraisal_kpis', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('assessment_attempt_id')->constrained('assessment_attempts')->cascadeOnDelete();
            $table->text('description');        // Task / Responsibility
            $table->text('target');             // Target / KPI
            $table->text('actual')->nullable(); // Actual Achieved
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->index('assessment_attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appraisal_kpis');
    }
};
