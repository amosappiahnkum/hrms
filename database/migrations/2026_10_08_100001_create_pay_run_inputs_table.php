<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The month's variable items for a pay run: hours, unit inputs, one-off earnings and deductions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_run_inputs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('pay_run_id')->constrained(indexName: 'run_inputs_run_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained(indexName: 'run_inputs_employee_fk')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained(indexName: 'run_inputs_component_fk')->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            // input (entered or imported) | overtime | time_input | loan
            $table->string('source')->default('input');
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'run_inputs_creator_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['pay_run_id', 'employee_id'], 'run_inputs_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_run_inputs');
    }
};
