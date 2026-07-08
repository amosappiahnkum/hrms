<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Nullable so training quiz attempts can exist without a window
            $table->foreignId('assessment_window_id')
                ->nullable()
                ->constrained('assessment_windows')
                ->cascadeOnDelete();

            $table->foreignId('user_id')->constrained();

            $table->enum('status', [
                'draft',
                'pending_supervisor',
                'pending_employee_acknowledgment',
                'supervisor_confirmed',
                'completed',
                'returned',
                'submitted',
                'pending_signatures',
            ])->default('draft');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->text('employee_comment')->nullable();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('supervisor_comment')->nullable();
            $table->timestamp('supervisor_confirmed_at')->nullable();

            // Direct assessment link (training quizzes — no window)
            $table->foreignId('assessment_id')
                ->nullable()
                ->constrained('assessments')
                ->nullOnDelete();

            // Scoring
            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('self_score', 5, 2)->nullable();
            $table->decimal('kpi_score', 5, 2)->nullable();
            $table->timestamp('finalized_at')->nullable();

            $table->timestamp('employee_signed_at')->nullable();
            $table->timestamp('supervisor_signed_at')->nullable();

            $table->timestamps();

            $table->unique(['assessment_window_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_attempts');
    }
};
