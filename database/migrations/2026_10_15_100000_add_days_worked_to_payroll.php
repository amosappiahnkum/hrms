<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_components', function (Blueprint $table) {
            // How a prorated amount is shared out: employment (joiners/leavers, by calendar days) or
            // days_worked (days worked ÷ working days in the period).
            $table->string('prorate_basis', 20)->default('employment')->after('prorate');
            // Where a per-unit component's quantity comes from: input (entered) or days_worked.
            $table->string('quantity_source', 20)->default('input')->after('unit');
        });

        Schema::table('payslips', function (Blueprint $table) {
            // The period's working days and the days worked, when a component used them.
            $table->unsignedSmallInteger('working_days')->nullable()->after('proration');
            $table->decimal('days_worked', 6, 2)->nullable()->after('working_days');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['working_days', 'days_worked']);
        });
        Schema::table('pay_components', function (Blueprint $table) {
            $table->dropColumn(['prorate_basis', 'quantity_source']);
        });
    }
};
