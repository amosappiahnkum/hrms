<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Yearly exchange rates to the base currency, for pay set in another currency (e.g. USD). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('currency', 3);
            $table->unsignedSmallInteger('year');
            // 1 unit of `currency` = `rate` units of the base currency.
            $table->decimal('rate', 14, 6);
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'fx_rates_creator_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['currency', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
