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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('question_category_id')
                ->nullable()
                ->constrained('question_categories')
                ->onDelete('set null');
            $table->enum('type', ['rating', 'yes_no', 'multiple_choice', 'open_text']);
            $table->text('text');
            $table->text('description')->nullable();
            $table->decimal('weight', 5, 2)->default(1.00);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->foreignId('depends_on_question_id')
                ->nullable()
                ->constrained('questions')
                ->onDelete('set null');
            $table->json('show_when_value')->nullable();
            $table->foreignId('user_id')->constrained();
            $table->enum('scope', ['hr', 'training'])->nullable();
            // When set, this question belongs to a specific course's question bank
            $table->foreignId('course_id')
                ->nullable()
                ->constrained('courses')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['question_category_id', 'is_active']);
            $table->index(['type', 'is_active']);
            $table->index(['scope', 'is_active']);
            $table->index(['course_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
