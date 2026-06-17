<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_resumptions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->timestamp('employee_confirmed_at')->nullable();
            $table->foreignId('hod_id')->nullable()->constrained('employees');
            $table->timestamp('hod_acknowledged_at')->nullable();
            $table->enum('status', ['pending_hod', 'completed'])->default('pending_hod');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_resumptions');
    }
};
