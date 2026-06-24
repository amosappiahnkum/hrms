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
        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('assessment_window_id')->constrained('assessment_windows')->onDelete('cascade');
            $table->foreignId('user_id')->constrained();
            $table->enum('status', [
                'draft',
                'pending_supervisor',
                'supervisor_confirmed',
                'completed',
                'returned',
                // kept for non-appraisal types
                'submitted',
            ])->default('draft');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();


            $table->text('employee_comment')->nullable();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('supervisor_comment')->nullable();
            $table->timestamp('supervisor_confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['assessment_window_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_attempts');
    }
};
