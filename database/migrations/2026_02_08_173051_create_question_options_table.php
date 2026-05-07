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
        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('question_id')
                ->constrained('questions')
                ->onDelete('cascade');
            $table->string('option_text');
            $table->string('option_value')->nullable();
            $table->integer('order')->default(0);
            $table->foreignId('user_id')->constrained();
            $table->timestamps();

            $table->index(['question_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_options');
    }
};
