<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay runs and payslips. A payslip stores every line as calculated, with a snapshot of who the
 * employee was and how they're paid, so a run never changes once approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_rate_sets', function (Blueprint $table) {
            // PAYE for non-residents: a flat rate on taxable income.
            $table->decimal('non_resident_rate', 5, 2)->default(25)->after('tier3_relief_limit_percent');
        });

        Schema::create('pay_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('type')->default('regular'); // regular | off_cycle
            $table->string('name');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date')->nullable();
            // draft | calculated | pending_approval | approved | paid
            $table->string('status')->default('draft');
            $table->foreignId('statutory_rate_set_id')->nullable()->constrained(indexName: 'pay_runs_rates_fk')->nullOnDelete();
            // {"USD": 15.2} used for this run; and the settings it was worked out with.
            $table->json('exchange_rates')->nullable();
            $table->json('settings')->nullable();
            $table->json('totals')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users', indexName: 'pay_runs_preparer_fk')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users', indexName: 'pay_runs_payer_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['year', 'month', 'type']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('pay_run_id')->constrained(indexName: 'payslips_run_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained(indexName: 'payslips_employee_fk')->cascadeOnDelete();
            // Name, staff id, position, department… as they were.
            $table->json('employee_snapshot');
            // How they're paid (method, bank, account), encrypted.
            $table->text('payment_snapshot')->nullable();
            // How they're paid, in plain columns for filtering (the snapshot is encrypted).
            $table->string('payment_method', 30)->nullable();
            $table->string('bank_name')->nullable();
            $table->decimal('proration', 6, 4)->default(1);
            $table->decimal('basic_salary', 14, 2)->default(0);
            $table->decimal('gross_pay', 14, 2)->default(0);
            $table->decimal('taxable_income', 14, 2)->default(0);
            // Earnings SSNIT was worked out on (after the cap), for off-cycle runs in the month.
            $table->decimal('ssnit_base', 14, 2)->default(0);
            $table->decimal('ssnit_employee', 14, 2)->default(0);
            $table->decimal('ssnit_employer', 14, 2)->default(0);
            $table->decimal('tier1', 14, 2)->default(0);
            $table->decimal('tier2', 14, 2)->default(0);
            $table->decimal('tier3', 14, 2)->default(0);
            $table->decimal('paye', 14, 2)->default(0);
            // Bonus taxed at the bonus rate, and that tax (part of PAYE), for the year's bonus allowance.
            $table->decimal('bonus_concession', 14, 2)->default(0);
            $table->decimal('bonus_tax', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net_pay', 14, 2)->default(0);
            $table->decimal('employer_cost', 14, 2)->default(0);
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->unique(['pay_run_id', 'employee_id'], 'payslips_run_employee_unique');
            $table->index(['pay_run_id', 'payment_method'], 'payslips_run_method_idx');
        });

        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained(indexName: 'payslip_lines_payslip_fk')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->nullable()->constrained(indexName: 'payslip_lines_component_fk')->nullOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('kind'); // earning | deduction | employer_contribution
            // profile | component | input | overtime | loan | statutory
            $table->string('source');
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('rate', 14, 4)->nullable();
            // In the original currency, and in the base currency.
            $table->decimal('original_amount', 14, 2)->nullable();
            $table->string('original_currency', 3)->nullable();
            $table->decimal('exchange_rate', 14, 6)->nullable();
            $table->decimal('amount', 14, 2);
            $table->boolean('taxable')->default(false);
            $table->boolean('ssnit_applicable')->default(false);
            $table->boolean('show_on_payslip')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('pay_runs');
        Schema::table('statutory_rate_sets', fn (Blueprint $table) => $table->dropColumn('non_resident_rate'));
    }
};
