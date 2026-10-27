<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banks employees are paid through: chosen on pay details and payment file layouts (not typed), so the same bank
 * is always the same bank (a payment file for a bank picks its employees by it). Duplicates are merged. Each bank
 * has its branches, with the sort code bank upload files ask for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            // E.g. a SWIFT or sort code, for payment files that ask for it.
            $table->string('code', 30)->nullable();
            $table->boolean('active')->default(true);
            // Merged into another bank (then soft deleted).
            $table->foreignId('merged_into_id')->nullable()->constrained('banks', indexName: 'banks_merged_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bank_branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bank_id')->constrained(indexName: 'bank_branches_bank_fk')->cascadeOnDelete();
            $table->string('name');
            // The bank-and-branch code payment files ask for (e.g. a 6-digit GhIPSS sort code).
            $table->string('sort_code', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('merged_into_id')->nullable()->constrained('bank_branches', indexName: 'bank_branches_merged_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_branches');
        Schema::dropIfExists('banks');
    }
};
