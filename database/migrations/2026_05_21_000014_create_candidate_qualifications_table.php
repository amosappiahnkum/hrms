<?php

use App\Models\Recruitment\Candidate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_qualifications', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignIdFor(Candidate::class)->constrained()->cascadeOnDelete();
            $table->string('institution');
            $table->string('award');
            $table->string('field_of_study')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('grade')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_qualifications');
    }
};
