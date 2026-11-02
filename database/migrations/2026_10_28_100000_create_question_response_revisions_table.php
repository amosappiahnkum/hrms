<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every change the appraiser makes to an employee's appraisal answer, for HR. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_response_revisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('question_response_id')->constrained('question_responses')->cascadeOnDelete();
            $table->foreignId('assessment_attempt_id')->constrained('assessment_attempts')->cascadeOnDelete();
            // What stood before this change (the appraiser's previous change, else the employee's answer) and after it.
            $table->text('previous_answer')->nullable();
            $table->integer('previous_score')->nullable();
            $table->text('answer')->nullable();
            $table->integer('score')->nullable();
            $table->text('justification')->nullable();
            $table->foreignId('revised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_response_revisions');
    }
};
