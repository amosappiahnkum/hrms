<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appraisal_trainings', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('assessment_attempt_id')->constrained('assessment_attempts')->cascadeOnDelete();
            $table->string('subject');                      // Subject area of workshop / course
            $table->string('type', 30)->default('course'); // seminar, workshop, course, conference, certification, other
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('source', 20)->default('manual'); // internal, certification, manual
            $table->foreignId('course_enrollment_id')->nullable()->constrained('course_enrollments')->nullOnDelete();
            $table->foreignId('employee_certification_id')->nullable()->constrained('employee_certifications')->nullOnDelete();
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->index('assessment_attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appraisal_trainings');
    }
};
