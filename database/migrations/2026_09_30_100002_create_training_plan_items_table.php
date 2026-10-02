<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_plan_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('training_plan_id')->constrained('training_plans')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('training_catalogue_item_id')->nullable()->constrained('training_catalogue_items')->nullOnDelete();

            // What is planned (copied from the catalogue, then editable) — changes need approval.
            $table->string('title');
            $table->string('nature');
            $table->foreignId('training_domain_id')->nullable()->constrained('training_domains')->nullOnDelete();
            $table->string('category');
            $table->string('source_of_need')->nullable();
            $table->string('supporting_record')->nullable();
            $table->string('quarter', 2);
            $table->decimal('days', 5, 1)->nullable();
            $table->decimal('cost', 12, 2)->default(0);
            $table->string('trainer')->nullable();
            $table->string('delivery')->default('external');

            // Progress — editable at any time without re-approval.
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->string('status')->default('not_started');
            $table->date('completed_at')->nullable();
            $table->text('comment')->nullable();

            $table->string('approval_status')->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_comment')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['training_plan_id', 'approval_status']);
            $table->index(['approval_status', 'planned_start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_plan_items');
    }
};
