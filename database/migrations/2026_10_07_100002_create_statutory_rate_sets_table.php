<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statutory rates (SSNIT, PAYE, reliefs, overtime and bonus tax) as dated sets maintained by HR.
 * A pay run uses the set in force for its period; a set must be confirmed before it is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_rate_sets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->date('effective_from');
            $table->text('notes')->nullable();
            // {employee_rate, employer_rate, tier1_rate, tier2_rate, max_insurable_earnings}
            $table->json('ssnit');
            // [{limit: monthly band width or null for "the rest", rate}], in order.
            $table->json('paye_bands');
            // [{code, name, annual_amount, max_units, percent_of_income}]
            $table->json('reliefs')->nullable();
            $table->decimal('tier3_relief_limit_percent', 5, 2)->nullable();
            // {annual_basic_threshold, percent_of_basic, lower_rate, higher_rate} (GRA junior-staff rules)
            $table->json('overtime_tax')->nullable();
            // {rate, percent_of_annual_basic}
            $table->json('bonus_tax')->nullable();
            // Checked against GRA/SSNIT by HR; pay runs refuse unconfirmed sets.
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users', indexName: 'stat_rates_confirmer_fk')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'stat_rates_creator_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_rate_sets');
    }
};
