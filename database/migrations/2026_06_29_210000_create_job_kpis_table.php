<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_kpis', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->text('description');
            $table->string('description_hash', 64);             // SHA-256 of trimmed lowercase description
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'description_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_kpis');
    }
};
