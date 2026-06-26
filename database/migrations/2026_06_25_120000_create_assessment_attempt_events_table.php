<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_attempt_events', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('assessment_attempt_id')->constrained('assessment_attempts')->cascadeOnDelete();
            $table->enum('event_type', [
                'submitted',
                'returned',
                'supervisor_confirmed',
                'hr_completed',
                'answers_edited',
            ]);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['assessment_attempt_id', 'created_at'], 'aae_attempt_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_attempt_events');
    }
};
