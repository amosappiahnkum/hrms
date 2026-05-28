<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_interviewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unique(['interview_id', 'employee_id'], 'ii_iv_emp_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_interviewers');
    }
};
