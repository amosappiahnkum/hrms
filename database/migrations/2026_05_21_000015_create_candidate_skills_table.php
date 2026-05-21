<?php

use App\Models\Recruitment\Candidate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_skills', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignIdFor(Candidate::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('level')->default('intermediate');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_skills');
    }
};
