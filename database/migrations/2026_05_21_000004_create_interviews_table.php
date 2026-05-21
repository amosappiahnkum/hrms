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
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignIdFor(Application::class)->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('interviewer_id')->nullable();
            $table->foreign('interviewer_id')->references('id')->on('employees')->nullOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('type')->default('in_person');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->string('outcome')->default('pending');
            $table->text('feedback')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};
