<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid();

            $table->string('title'); // e.g. "2026 Performance Review"
            $table->text('description')->nullable();

            $table->enum('type', [
                'appraisal',
                'training',
                'survey',
            ]);

            $table->nullableMorphs('assignable');
            // Employee, Department, Role, etc.

            $table->boolean('is_active')->default(true);
            // Appraisal: also set the KPI targets for the next period (into the employee's KPI plan).
            $table->boolean('include_next_period_targets')->default(false);
            // Appraisal: share of the overall score from the supervisor's section (the employee's section has the rest).
            $table->unsignedTinyInteger('supervisor_weight')->default(60);

            $table->foreignId('user_id')->constrained();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
