<?php

use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\JobOpening;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignIdFor(Candidate::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(JobOpening::class)->constrained()->cascadeOnDelete();
            $table->string('status')->default('applied');
            $table->longText('cover_letter')->nullable();
            $table->timestamp('applied_at')->useCurrent();
            $table->foreignIdFor(Employee::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
