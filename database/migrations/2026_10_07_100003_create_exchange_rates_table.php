<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dated exchange rates to the base currency, for pay set in another currency (e.g. USD). A pay run uses the latest
 * rate on or before its rate date; basic salary can have its own rate type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('currency', 3);
            // standard (everything) | basic (basic salary, when the organization converts it at its own rate)
            $table->string('rate_type', 20)->default('standard');
            // In force from this date until a later rate of the same currency and type.
            $table->date('effective_date');
            // 1 unit of `currency` = `rate` units of the base currency.
            $table->decimal('rate', 14, 6);
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'fx_rates_creator_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['currency', 'rate_type', 'effective_date'], 'fx_rates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
