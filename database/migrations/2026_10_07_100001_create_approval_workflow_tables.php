<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable approval chains for payroll processes (overtime, loans, time inputs, pay runs):
 * HR defines the steps; requests follow a snapshot of them taken when they start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('process'); // overtime | loan | time_input | pay_run
            $table->string('name');
            // The workflow a process uses unless something (e.g. a loan type) names another.
            $table->boolean('is_default')->default(false);
            // No one may approve two steps of the same request.
            $table->boolean('distinct_approvers')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['process', 'is_default']);
        });

        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('approval_workflow_id')->constrained(indexName: 'aw_steps_workflow_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            // supervisor | department_head | parent_department_head | role | permission | users
            $table->string('approver_type');
            // The role or permission name, or user ids.
            $table->json('approver_value')->nullable();
            // Fields this step may change on the request, e.g. ["approved_hours"].
            $table->json('can_adjust')->nullable();
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->morphs('subject', 'approvals_subject_idx');
            $table->string('process');
            $table->foreignId('approval_workflow_id')->nullable()->constrained(indexName: 'approvals_workflow_fk')->nullOnDelete();
            // The person the request is about (whose supervisor/department decide who approves).
            $table->foreignId('employee_id')->nullable()->constrained(indexName: 'approvals_employee_fk')->nullOnDelete();
            $table->string('status')->default('pending'); // pending | approved | rejected | cancelled
            $table->unsignedSmallInteger('current_position')->nullable();
            // The steps as they were when the request started, with the people resolved for each.
            $table->json('steps');
            // Who may act now (user ids), for "waiting for me" lists.
            $table->json('current_approver_ids')->nullable();
            $table->boolean('distinct_approvers')->default(false);
            $table->foreignId('started_by')->nullable()->constrained('users', indexName: 'approvals_starter_fk')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['process', 'status']);
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('approval_id')->constrained(indexName: 'approval_decisions_approval_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('step_name');
            $table->string('decision'); // approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('users', indexName: 'approval_decisions_user_fk')->nullOnDelete();
            $table->text('comment')->nullable();
            // Values the approver changed, e.g. {"approved_hours": {"from": 6, "to": 4}}.
            $table->json('adjustments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('approval_workflows');
    }
};
