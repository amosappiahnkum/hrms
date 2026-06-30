<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('status', ['enrolled', 'in_progress', 'completed', 'failed'])
                ->default('enrolled');

            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('due_date')->nullable();       // from assignment or manual override
            $table->timestamp('completed_at')->nullable();

            // Frozen once completed: overall quiz/assessment score across the course
            $table->decimal('final_score', 5, 2)->nullable();

            $table->timestamps();

            // One enrollment per employee per course
            $table->unique(['course_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_enrollments');
    }
};
