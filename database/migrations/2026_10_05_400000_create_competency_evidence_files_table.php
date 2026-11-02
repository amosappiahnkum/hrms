<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files evidencing competence (SOP 5.3.3 "evidence of competence"): a certificate, an observation
 * record, a work sample. Attached to a rating or to a development action's effectiveness check.
 * Several rows may point at one stored file: evidence is carried forward to later assessments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competency_evidence_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->morphs('evidenceable', 'comp_evidence_target_idx');
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->foreignId('uploaded_by')->nullable()->constrained('users', indexName: 'comp_evidence_uploader_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_evidence_files');
    }
};
