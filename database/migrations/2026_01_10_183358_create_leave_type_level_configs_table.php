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
        Schema::table('leave_types', function (Blueprint $table) {
            $table->integer('max_days_per_request')->nullable(); //Size of a single application
            $table->boolean('requires_document')->default(false);
        });

        Schema::create('leave_type_level_configs', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('leave_type_id')->constrained()->onDelete('cascade');
            $table->foreignId('job_category_id')->constrained()->onDelete('cascade');
            $table->integer('number_of_days')->default(0);
            $table->boolean('allow_half_day')->default(false);
            $table->boolean('allow_carry_forward')->default(false);
            $table->integer('maximum_allotment')->nullable(); // Total entitlement per period
            $table->integer('maximum_consecutive_days')->nullable(); // max uninterrupted days
            $table->integer('max_days_per_request')->nullable(); //Size of a single application
            $table->integer('should_request_before')->nullable();
            $table->boolean('carry_forward_allowed')->default(false);
            $table->integer('max_carry_forward_days')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->timestamps();

            $table->unique(['leave_type_id', 'job_category_id'], 'leave_configs_leave_type_job_category_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_type_level_configs');

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('max_days_per_request');
            $table->dropColumn('requires_document');
        });
    }
};
