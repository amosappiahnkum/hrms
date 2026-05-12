<?php

use App\Models\Config\LeaveType;
use App\Models\SelfService\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('leave_requests', static function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Employee::class)->constrained();
            $table->foreignIdFor(Employee::class, 'supervisor_id')->constrained('employees');
            $table->foreignIdFor(Employee::class, 'hr_id')->nullable()->comment('who in HR approved')->constrained('employees');
            $table->foreignIdFor(LeaveType::class)->constrained();
            $table->integer('days_requested');
            $table->integer('days_approved')->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->longText('reason');
            $table->boolean('viewed')->default(false);
            $table->string('status')->default('pending');
            $table->string('hr_status')->default('pending');
            $table->date('sup_approval')->nullable()->comment('Date approved by supervisor');
            $table->longText('sup_reason')->nullable();
            $table->date('hr_approval')->nullable()->comment('Date approved by HR');
            $table->longText('hr_reason')->nullable();
            $table->date('moved')->comment('Moved for HR approval')->nullable();
            $table->foreignIdFor(Employee::class, 'moved_by')->nullable()->constrained('employees');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('leave_requests');
    }
};
