<?php

use App\Models\Department;
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
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dropColumn('status');
            // Workflow status
            $table->enum('status', [
                'pending',
                'hod_approved',
                'hod_rejected',
                'moved',
                'hr_approved',
                'hr_rejected',
                'cancelled'
            ])->default('pending');
            $table->foreignIdFor(Department::class)->constrained();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->index(['employee_id', 'status']);
            $table->index(['employee_id', 'status', 'leave_type_id']);
        });

        Schema::create('leave_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approved_by')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['hod', 'hr']);
            $table->enum('decision', ['approved', 'rejected']);
            $table->text('comment')->nullable();
            $table->integer('days_approved')->default(0)->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['leave_request_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_approvals');

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('approved_at');
            $table->dropColumn('rejected_at');
            $table->dropForeign('app_leave_requests_user_id_foreign');
            $table->dropColumn('user_id');
        });
    }
};
