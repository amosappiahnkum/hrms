<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overtime: the types an organization pays (each priced by a pay component) and the requests that
 * go through its approval workflow and, once approved, into a pay run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            // How it's paid: an hourly-multiplier or per-hour component.
            $table->foreignId('pay_component_id')->constrained(indexName: 'ot_types_component_fk')->restrictOnDelete();
            $table->string('applies_on')->default('any'); // weekday | weekend | holiday | any
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'ot_requests_employee_fk')->cascadeOnDelete();
            $table->foreignId('overtime_type_id')->constrained(indexName: 'ot_requests_type_fk')->restrictOnDelete();
            $table->date('work_date');
            $table->decimal('hours_requested', 5, 2);
            // Starts as requested; approvers may change it where their step allows.
            $table->decimal('approved_hours', 5, 2);
            $table->string('location')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users', indexName: 'ot_requests_requester_fk')->nullOnDelete();
            // The pay run that paid it (claimed when the run is calculated).
            $table->foreignId('pay_run_id')->nullable()->constrained(indexName: 'ot_requests_run_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'work_date'], 'ot_requests_employee_date_idx');
            $table->index(['status', 'pay_run_id'], 'ot_requests_payable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('overtime_types');
    }
};
