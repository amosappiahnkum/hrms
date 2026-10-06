<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank and mobile money payment files, laid out the way each bank wants (configured by HR, not
 * coded per bank); and an account code per pay component for the journal export.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_file_layouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('payment_method')->default('bank'); // bank | mobile_money
            // Only employees paid through this bank (e.g. a bank's own upload format); null: everyone.
            $table->string('bank_name')->nullable();
            $table->string('format')->default('csv'); // csv | xlsx
            $table->string('delimiter', 3)->default(',');
            $table->boolean('include_header')->default(true);
            // [{field, heading}] in order.
            $table->json('columns');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('pay_components', function (Blueprint $table) {
            $table->string('account_code', 50)->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('pay_components', fn (Blueprint $table) => $table->dropColumn('account_code'));
        Schema::dropIfExists('payment_file_layouts');
    }
};
