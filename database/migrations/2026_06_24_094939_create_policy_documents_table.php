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
        Schema::create('policy_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('category', ['policy', 'manual', 'handbook', 'notice', 'form', 'other'])
                  ->default('policy');

            // Stored file info
            $table->string('file_path');          // S3 object key
            $table->string('preview_path')->nullable();
            $table->string('file_name');          // original filename for Content-Disposition
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type')->default('application/pdf');

            // Access control
            $table->boolean('is_downloadable')->default(true);
            $table->enum('scope_type', ['all', 'department', 'job_category', 'role'])
                  ->default('all');
            $table->json('scope_ids')->nullable(); // dept IDs, job_category IDs, or ['hod']

            $table->boolean('is_active')->default(true);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['scope_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_documents');
    }
};
