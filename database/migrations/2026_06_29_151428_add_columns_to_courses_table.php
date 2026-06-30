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
        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->foreignId('course_enrollment_id')
                ->nullable()
                ->after('assessment_id')
                ->constrained('course_enrollments')
                ->nullOnDelete();

            $table->foreignId('course_chapter_id')
                ->nullable()
                ->after('course_enrollment_id')
                ->constrained('course_chapters')
                ->nullOnDelete();

            $table->index(['course_enrollment_id', 'course_chapter_id'], 'aa_enrollment_chapter_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->dropForeign(['course_enrollment_id']);
            $table->dropForeign(['course_chapter_id']);
            $table->dropIndex('aa_enrollment_chapter_idx');
            $table->dropColumn(['course_enrollment_id', 'course_chapter_id']);
        });
    }
};
