<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Competency matrix (AI-HR-CM-FM-01): the competency library, what each position requires,
 * assessments of employees against it, and the actions that close the gaps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('group');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['group', 'name'], 'competencies_group_name_idx');
        });

        Schema::create('position_competencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('required_level');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['position_id', 'competency_id'], 'position_comp_idx');
        });

        Schema::create('competency_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // The role assessed against, kept as it was at the time.
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->date('assessed_on')->nullable();
            $table->date('next_review_on')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status', 'completed_at'], 'comp_assessments_employee_idx');
        });

        Schema::create('competency_ratings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('competency_assessment_id')->constrained(indexName: 'comp_ratings_assessment_fk')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained(indexName: 'comp_ratings_competency_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('required_level')->nullable();
            // Null: not applicable / not rated.
            $table->unsignedTinyInteger('level')->nullable();
            $table->text('evidence')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['competency_assessment_id', 'competency_id'], 'comp_ratings_lookup_idx');
        });

        Schema::create('competency_development_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'comp_actions_employee_fk')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained(indexName: 'comp_actions_competency_fk')->cascadeOnDelete();
            $table->foreignId('competency_assessment_id')->nullable()->constrained(indexName: 'comp_actions_assessment_fk')->nullOnDelete();
            $table->foreignId('training_plan_item_id')->nullable()->constrained(indexName: 'comp_actions_training_fk')->nullOnDelete();
            $table->string('method');
            $table->string('status')->default('planned');
            $table->text('description')->nullable();
            $table->date('due_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'comp_actions_creator_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'competency_id', 'status'], 'comp_actions_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_development_actions');
        Schema::dropIfExists('competency_ratings');
        Schema::dropIfExists('competency_assessments');
        Schema::dropIfExists('position_competencies');
        Schema::dropIfExists('competencies');
    }
};
