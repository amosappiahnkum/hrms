<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The authorization register (SOP definitions, 4.2, 4.4, 5.3.3): activities that need formal
 * authorization, what competence and certificates each requires, and who is authorized for them, on
 * whose authority and until when. Starts empty; HR sets up the activities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // The position it's usually for (informational; anyone eligible can be authorized).
            $table->foreignId('position_id')->nullable()->constrained(indexName: 'auth_activities_position_fk')->nullOnDelete();
            // Default length of a grant; none: until revoked.
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('authorization_activity_requirements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('authorization_activity_id')->constrained(indexName: 'auth_reqs_activity_fk')->cascadeOnDelete();
            // One of: a competency at a minimum level, or a valid certificate of a type.
            $table->foreignId('competency_id')->nullable()->constrained(indexName: 'auth_reqs_competency_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('min_level')->nullable();
            $table->foreignId('certification_type_id')->nullable()->constrained(indexName: 'auth_reqs_cert_type_fk')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('employee_authorizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'emp_auth_employee_fk')->cascadeOnDelete();
            $table->foreignId('authorization_activity_id')->constrained(indexName: 'emp_auth_activity_fk')->cascadeOnDelete();
            $table->string('status'); // recommended | authorized | suspended | declined | revoked | expired
            $table->foreignId('recommended_by')->nullable()->constrained('users', indexName: 'emp_auth_recommender_fk')->nullOnDelete();
            $table->timestamp('recommended_at')->nullable();
            $table->text('recommendation_note')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users', indexName: 'emp_auth_granter_fk')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable();
            $table->date('valid_until')->nullable();
            // Why it was declined, suspended or revoked.
            $table->text('reason')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users', indexName: 'emp_auth_changer_fk')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('expiry_reminded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status'], 'emp_auth_employee_status_idx');
            $table->index(['authorization_activity_id', 'status'], 'emp_auth_activity_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_authorizations');
        Schema::dropIfExists('authorization_activity_requirements');
        Schema::dropIfExists('authorization_activities');
    }
};
