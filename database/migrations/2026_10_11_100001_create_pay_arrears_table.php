<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back pay: when a raise is backdated into months already paid, the difference for each of those
 * payslips, and the run that pays it (so it's paid once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_arrears', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'pay_arrears_employee_fk')->cascadeOnDelete();
            // The earlier payslip that was short.
            $table->foreignId('payslip_id')->constrained(indexName: 'pay_arrears_payslip_fk')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->foreignId('pay_run_id')->constrained(indexName: 'pay_arrears_run_fk')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_arrears');
    }
};
