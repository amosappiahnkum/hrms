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
        Schema::table('employees', function (Blueprint $table) {
            $table->longText('bio')->nullable();
            $table->jsonb('research_interests')->default('[]')->nullable();
            $table->jsonb('specializations')->default('[]')->nullable();
        });

        Schema::table('next_of_kin', function (Blueprint $table) {
            $table->integer('user_id')->nullable();
        });
        Schema::table('job_details', function (Blueprint $table) {
            $table->longText('room')->nullable();
        });

        Schema::table('contact_details', function (Blueprint $table) {
            $table->jsonb('social_links')->nullable();
        });

        Schema::table('education', function (Blueprint $table) {
            $table->string('field')->nullable();
            $table->string('country')->nullable();
        });

        Schema::table('publications', function (Blueprint $table) {
            $table->string('type')->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('role')->nullable();
            $table->year('end_year')->nullable();
            $table->string('status')->nullable();
            $table->jsonb('collaborators')->nullable();
        });


        Schema::table('grant_and_funds', function (Blueprint $table) {
            $table->string('currency')->nullable();
            $table->year('start')->nullable();
            $table->year('end')->nullable();
        });

        Schema::table('experiences', function (Blueprint $table) {
            $table->integer('user_id')->nullable();
        });

        Schema::table('previous_positions', function (Blueprint $table) {
            $table->integer('department_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('bio');
            $table->dropColumn('research_interests');
            $table->dropColumn('specializations');
        });

        Schema::table('job_details', function (Blueprint $table) {
            $table->dropColumn('room');
        });

        Schema::table('contact_details', function (Blueprint $table) {
            $table->dropColumn('social_links');
        });

        Schema::table('publications', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('role');
            $table->dropColumn('end_year');
            $table->dropColumn('status');
            $table->dropColumn('collaborators');
        });

        Schema::table('grant_and_funds', function (Blueprint $table) {
            $table->dropColumn('currency');
            $table->dropColumn('start');
            $table->dropColumn('end');
        });

        Schema::table('education', function (Blueprint $table) {
            $table->dropColumn('field');
            $table->dropColumn('country');
        });

        Schema::table('experiences', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('previous_positions', function (Blueprint $table) {
            $table->dropColumn('department_id');
        });
        Schema::table('next_of_kin', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }
};
