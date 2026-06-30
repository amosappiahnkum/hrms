<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_materials', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('chapter_id')
                ->constrained('course_chapters')
                ->cascadeOnDelete();

            $table->string('title');
            $table->enum('type', ['document', 'video', 'audio', 'video_link', 'text', 'quiz']);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('order')->default(0);

            // For uploaded files (document / video / audio)
            $table->string('file_path')->nullable();   // S3 object key
            $table->string('preview_path')->nullable();
            $table->string('file_name')->nullable();   // original filename
            $table->unsignedBigInteger('file_size')->nullable(); // bytes
            $table->string('mime_type')->nullable();

            // For external video links
            $table->string('url')->nullable();
            $table->longText('text_content')->nullable();
            // For video / audio: estimated length shown to employee
            $table->unsignedInteger('duration_seconds')->nullable();

            // Documents only: controls Content-Disposition header on signed URL
            $table->boolean('is_downloadable')->default(true);

            // Quiz materials
            $table->foreignId('quiz_assessment_id')
                ->nullable()
                ->constrained('assessments')
                ->nullOnDelete();
            $table->boolean('quiz_required')->default(false);

            $table->timestamps();

            $table->index(['chapter_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_materials');
    }
};
