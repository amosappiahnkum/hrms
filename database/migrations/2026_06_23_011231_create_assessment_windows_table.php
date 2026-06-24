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
        Schema::create('assessment_windows', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('assessment_id')->constrained('assessments')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->enum('status', ['draft', 'open', 'closed'])->default('draft');
            $table->foreignId('user_id')->constrained();
            $table->timestamps();

            $table->index(['assessment_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_windows');
    }
};
