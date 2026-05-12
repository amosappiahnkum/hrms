<?php

use App\Models\SelfService\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('employee_supervisors', static function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Employee::class, 'supervisor_id')->constrained('employees');
            $table->foreignIdFor(Employee::class, 'employee_id')->constrained('employees');
            $table->enum('report_method', ['Direct', 'In-Direct'])->default('Direct');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_supervisors');
    }
};
