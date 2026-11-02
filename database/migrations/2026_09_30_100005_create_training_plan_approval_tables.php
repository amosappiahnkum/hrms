<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who validates and approves training plans, in order. The last level gives final approval.
        Schema::create('training_plan_approval_levels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->unsignedTinyInteger('position')->default(0);
            // all: everyone in the level signs; any: one of them is enough.
            $table->string('rule', 10)->default('any');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('training_plan_approval_level_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_plan_approval_level_id')->constrained('training_plan_approval_levels', indexName: 'tp_level_user_level_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unique(['training_plan_approval_level_id', 'user_id'], 'tp_level_user_unique');
        });

        // Each person's decision at a level of a submission round.
        Schema::create('training_plan_signoffs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('training_plan_id')->constrained('training_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->unsignedTinyInteger('level');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision', 10); // approved | rejected
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['training_plan_id', 'round', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_plan_signoffs');
        Schema::dropIfExists('training_plan_approval_level_user');
        Schema::dropIfExists('training_plan_approval_levels');
    }
};
