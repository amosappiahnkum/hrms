<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('thumbnail_path')->nullable();  // S3 key
            $table->string('thumbnail_url')->nullable();
            $table->foreignId('course_category_id')
                ->nullable()
                ->constrained('course_categories')
                ->nullOnDelete();

            $table->unsignedInteger('duration_minutes')->nullable(); // optional estimated duration

            // Optional final quiz (FK to assessments, type = 'training')
            $table->foreignId('final_quiz_id')
                ->nullable()
                ->constrained('assessments')
                ->nullOnDelete();
            $table->unsignedTinyInteger('passing_score')->nullable(); // 0–100, null = no pass requirement

            $table->boolean('is_published')->default(false);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // created by

            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_published', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
