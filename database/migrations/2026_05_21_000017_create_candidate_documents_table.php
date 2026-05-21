<?php

use App\Models\Recruitment\Candidate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignIdFor(Candidate::class)->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('display_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->bigInteger('size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_documents');
    }
};
