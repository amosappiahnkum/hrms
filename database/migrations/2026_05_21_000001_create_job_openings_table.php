<?php

use App\Models\Config\Department;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->string('title');
            $table->foreignIdFor(Position::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Department::class)->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->string('location')->nullable();
            $table->string('status')->default('draft');
            $table->date('deadline')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_openings');
    }
};
