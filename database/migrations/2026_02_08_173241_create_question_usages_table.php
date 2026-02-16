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
        Schema::create('question_usages', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('question_id')
                ->constrained('questions')
                ->onDelete('cascade');
            $table->morphs('usable'); // usable_type, usable_id
            $table->integer('order')->default(0);
            $table->decimal('custom_weight', 5, 2)->nullable();
            $table->boolean('is_required_override')->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();

            $table->unique(['question_id', 'usable_type', 'usable_id'], 'question_usage_unique');
//            $table->index(['usable_type', 'usable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_usages');
    }
};
