<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedSmallInteger('year');
            $table->string('title');
            // Planned cost ÷ budget_factor = estimated budget (0.80 leaves a 25% contingency).
            $table->decimal('budget_factor', 4, 2)->default(0.80);
            // While open, heads of department add the trainings their staff need.
            $table->date('collection_starts_on')->nullable();
            $table->date('collection_ends_on')->nullable();

            $table->string('approval_status')->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            // The approval levels as configured when the plan was submitted, the level it is waiting
            // on (0-based) and the submission round its sign-offs belong to.
            $table->json('approval_chain')->nullable();
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->unsignedSmallInteger('approval_round')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_comment')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['year', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_plans');
    }
};
