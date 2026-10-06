<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff loans and advances: the types an organization offers (with its limits and interest),
 * requests going through approval, the repayment schedule, and every repayment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('interest_method')->default('none'); // none | flat | reducing_balance
            $table->decimal('interest_rate', 6, 3)->default(0); // % a year
            // Limits: any left empty doesn't apply.
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->decimal('max_times_basic', 6, 2)->nullable();
            $table->unsignedSmallInteger('max_tenor_months')->default(12);
            $table->unsignedSmallInteger('min_service_months')->nullable();
            $table->unsignedTinyInteger('max_active_loans')->nullable(); // of this type
            $table->decimal('max_deduction_percent', 5, 2)->nullable(); // of net pay, all loans together
            $table->boolean('requires_guarantor')->default(false);
            // Its own workflow; otherwise the default loan workflow.
            $table->foreignId('approval_workflow_id')->nullable()->constrained(indexName: 'loan_types_workflow_fk')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'loans_employee_fk')->cascadeOnDelete();
            $table->foreignId('loan_type_id')->constrained(indexName: 'loans_type_fk')->restrictOnDelete();
            $table->decimal('amount_requested', 14, 2);
            // Starts as requested; approvers may lower it where their step allows.
            $table->decimal('amount', 14, 2);
            $table->unsignedSmallInteger('tenor_months');
            $table->text('purpose')->nullable();
            $table->foreignId('guarantor_id')->nullable()->constrained('employees', indexName: 'loans_guarantor_fk')->nullOnDelete();
            // Copied from the type when requested, so later changes to the type don't alter it.
            $table->string('interest_method');
            $table->decimal('interest_rate', 6, 3)->default(0);
            // pending | approved | rejected | cancelled | active | settled
            $table->string('status')->default('pending');
            $table->date('disbursed_on')->nullable();
            $table->string('disbursement_method')->nullable(); // bank | cash | mobile_money | payroll
            $table->string('disbursement_reference')->nullable();
            // Paid out on a payslip: the run that did it.
            $table->foreignId('disbursement_run_id')->nullable()->constrained('pay_runs', indexName: 'loans_disb_run_fk')->nullOnDelete();
            $table->decimal('total_repayable', 14, 2)->nullable();
            $table->date('settled_on')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users', indexName: 'loans_requester_fk')->nullOnDelete();
            $table->foreignId('disbursed_by')->nullable()->constrained('users', indexName: 'loans_disburser_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status'], 'loans_employee_status_idx');
        });

        Schema::create('loan_instalments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('loan_id')->constrained(indexName: 'loan_inst_loan_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('principal', 14, 2);
            $table->decimal('interest', 14, 2)->default(0);
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('due'); // due | paid | paused
            // The run deducting it (claimed when the run is calculated).
            $table->foreignId('pay_run_id')->nullable()->constrained(indexName: 'loan_inst_run_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'year', 'month'], 'loan_inst_due_idx');
        });

        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('loan_id')->constrained(indexName: 'loan_repay_loan_fk')->cascadeOnDelete();
            $table->foreignId('loan_instalment_id')->nullable()->constrained(indexName: 'loan_repay_inst_fk')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->string('source'); // payroll | manual
            $table->foreignId('pay_run_id')->nullable()->constrained(indexName: 'loan_repay_run_fk')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users', indexName: 'loan_repay_recorder_fk')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('loan_instalments');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('loan_types');
    }
};
