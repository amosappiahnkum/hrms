<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus tax and off-cycle runs: components can be bonuses (taxed at the bonus rate up to a share
 * of annual basic), and payslips keep what later runs in the month or year need to tax on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_components', function (Blueprint $table) {
            $table->boolean('is_bonus')->default(false)->after('taxable');
        });

        Schema::table('payslips', function (Blueprint $table) {
            // Earnings SSNIT was worked out on (after the cap).
            $table->decimal('ssnit_base', 14, 2)->default(0)->after('taxable_income');
            // Bonus taxed at the bonus rate, and that tax (part of PAYE).
            $table->decimal('bonus_concession', 14, 2)->default(0)->after('paye');
            $table->decimal('bonus_tax', 14, 2)->default(0)->after('bonus_concession');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', fn (Blueprint $table) => $table->dropColumn(['ssnit_base', 'bonus_concession', 'bonus_tax']));
        Schema::table('pay_components', fn (Blueprint $table) => $table->dropColumn('is_bonus'));
    }
};
