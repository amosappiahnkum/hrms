<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapter_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')
                ->constrained('course_enrollments')
                ->cascadeOnDelete();
            $table->foreignId('chapter_id')
                ->constrained('course_chapters')
                ->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            // The AssessmentAttempt that satisfies this chapter's quiz (if any)
            $table->foreignId('quiz_attempt_id')
                ->nullable()
                ->constrained('assessment_attempts')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['enrollment_id', 'chapter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapter_progress');
    }
};
