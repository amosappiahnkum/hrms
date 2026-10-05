<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The lines a payslip is made of (basic, allowances, overtime types, deductions…), defined by HR. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_components', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 30);
            $table->string('name');
            $table->string('kind'); // earning | deduction | employer_contribution
            // fixed | percent_of_basic | hourly_multiplier | rate_per_unit | manual
            $table->string('calculation');
            // The amount, percentage, multiplier or unit rate (by calculation).
            $table->decimal('rate', 14, 4)->nullable();
            // For fixed amounts and unit rates set in another currency; null: the base currency.
            $table->string('currency', 3)->nullable();
            $table->string('unit', 30)->nullable(); // e.g. day, hour (rate_per_unit)
            // Earnings: counts as taxable income. Deductions: taken before tax.
            $table->boolean('taxable')->default(true);
            $table->boolean('ssnit_applicable')->default(false);
            // A standing item employees are given (vs. entered per pay run).
            $table->boolean('recurring')->default(false);
            $table->boolean('prorate')->default(true);
            $table->boolean('show_on_payslip')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('active')->default(true);
            // Built in (basic salary): can't be removed or have its code changed.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_components');
    }
};
