<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a completed training is evaluated: the participant's feedback soon after, and their
 * supervisor's review some months later on whether it was applied on the job (SOP 5.3.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_evaluations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('training_plan_item_id')->constrained(indexName: 'tev_item_fk')->cascadeOnDelete();
            $table->string('type'); // participant_feedback | supervisor_review
            // Who is asked; null when nobody could be found (HR answers instead).
            $table->foreignId('evaluator_id')->nullable()->constrained('users', indexName: 'tev_evaluator_fk')->nullOnDelete();
            $table->date('due_on');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users', indexName: 'tev_submitter_fk')->nullOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('applied_on_job')->nullable();
            $table->json('answers')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['training_plan_item_id', 'type'], 'tev_item_type_idx');
            $table->index(['evaluator_id', 'submitted_at'], 'tev_evaluator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_evaluations');
    }
};
