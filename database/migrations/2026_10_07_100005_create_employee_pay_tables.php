<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each employee is paid and how: a dated pay profile (basic salary, currency, payment details,
 * pension schemes, reliefs) and their recurring pay components. A salary change is a new profile
 * from a date, so past periods keep theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_pay_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'pay_profiles_employee_fk')->cascadeOnDelete();
            $table->date('effective_from');
            $table->decimal('basic_salary', 14, 2);
            $table->string('currency', 3)->nullable(); // null: the base currency
            // A contract rate for converting this employee's basic salary; null: the pay run's rate.
            $table->decimal('fixed_exchange_rate', 14, 6)->nullable();
            $table->string('payment_method')->default('bank'); // bank | mobile_money | cash | cheque
            // The bank (from the banks list), and its name as it was when saved (for display and payslips).
            $table->foreignId('bank_id')->nullable()->constrained(indexName: 'pay_profiles_bank_fk')->nullOnDelete();
            $table->string('bank_name')->nullable();
            // The branch (from the bank's branches), and its name as it was when saved.
            $table->foreignId('bank_branch_id')->nullable()->constrained(indexName: 'pay_profiles_branch_fk')->nullOnDelete();
            $table->string('bank_branch')->nullable();
            $table->string('account_name')->nullable();
            // Encrypted at rest.
            $table->text('account_number')->nullable();
            $table->string('mobile_money_provider')->nullable();
            $table->text('mobile_money_number')->nullable();
            // Keyed hashes of the numbers (see EmployeePayProfile::fingerprint): find two employees paid into the same
            // account without decrypting every number. One-way: they can't give the number back.
            $table->string('account_fingerprint', 64)->nullable()->index();
            $table->string('mobile_money_fingerprint', 64)->nullable()->index();
            // Null: the Ghana Card PIN from contact details (the individual TIN since 2021).
            $table->string('tin')->nullable();
            $table->string('tier2_scheme')->nullable();
            $table->string('tier3_scheme')->nullable();
            $table->decimal('tier3_percent', 5, 2)->nullable();
            // [{code, units}] claimed from the statutory reliefs.
            $table->json('reliefs')->nullable();
            $table->boolean('tax_resident')->default(true);
            // Null: follow the overtime policy; true/false: an exception for this employee.
            $table->boolean('overtime_eligible')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'pay_profiles_creator_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'effective_from'], 'pay_profiles_employee_date_idx');
        });

        Schema::create('employee_pay_components', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'emp_pay_comp_employee_fk')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained(indexName: 'emp_pay_comp_component_fk')->cascadeOnDelete();
            // Null: the component's own rate.
            $table->decimal('amount', 14, 4)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'effective_from'], 'emp_pay_comp_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_pay_components');
        Schema::dropIfExists('employee_pay_profiles');
    }
};
