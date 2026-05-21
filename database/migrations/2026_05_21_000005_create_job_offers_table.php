<?php

use App\Models\Recruitment\Application;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignIdFor(Application::class)->constrained()->cascadeOnDelete();
            $table->decimal('salary', 12, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_offers');
    }
};
