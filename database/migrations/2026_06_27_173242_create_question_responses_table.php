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
        Schema::create('question_responses', function (Blueprint $table) {
            $table->id();
            $table->uuid();

            $table->foreignId('question_id')->constrained('questions');
            $table->foreignId('user_id');

            $table->morphs('respondable');
            // appraisal, quiz attempt, etc.

            $table->text('answer')->nullable();
            $table->integer('score')->nullable();

            $table->text('supervisor_answer')->nullable();
            $table->integer('supervisor_score')->nullable();
            $table->foreignId('supervisor_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('supervisor_updated_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_responses');
    }
};
