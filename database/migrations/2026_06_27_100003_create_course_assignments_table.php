<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            // Mirrors PolicyDocument's scoping pattern
            $table->enum('scope_type', ['all', 'department', 'job_category', 'role', 'employee']);
            // IDs of departments / job_categories / role slugs / user IDs depending on scope_type
            // Null when scope_type = 'all'
            $table->json('scope_ids')->nullable();

            $table->timestamp('due_date')->nullable(); // optional deadline for this assignment

            $table->timestamps();

            $table->index(['course_id', 'scope_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_assignments');
    }
};
