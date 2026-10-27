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
            $table->uuid()->unique();
            $table->string('name');
            // How it's paid: an hourly-multiplier or per-hour component.
            $table->foreignId('pay_component_id')->constrained(indexName: 'ot_types_component_fk')->restrictOnDelete();
            $table->string('applies_on')->default('any'); // weekday | weekend | holiday | any
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
            $table->softDeletes();
        });

        // Several dates requested at once: one approval for all of them (each date is still its own request below,
        // with the shared details copied onto it).
        Schema::create('overtime_request_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'ot_groups_employee_fk')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users', indexName: 'ot_groups_requester_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'ot_requests_employee_fk')->cascadeOnDelete();
            // Requested with other dates (approved together), or on its own.
            $table->foreignId('overtime_request_group_id')->nullable()->constrained(indexName: 'ot_requests_group_fk')->nullOnDelete();
            $table->foreignId('overtime_type_id')->constrained(indexName: 'ot_requests_type_fk')->restrictOnDelete();
            // An any-day type paid on top for the same hours (e.g. offshore, on top of weekend overtime).
            $table->foreignId('addon_type_id')->nullable()->constrained('overtime_types', indexName: 'ot_requests_addon_fk')->nullOnDelete();
            $table->date('work_date');
            $table->decimal('hours_requested', 5, 2);
            // Starts as requested; approvers may change it where their step allows.
            $table->decimal('approved_hours', 5, 2);
            // Free text from before sites existed; new requests name a site.
            $table->string('location')->nullable();
            $table->unsignedBigInteger('work_site_id')->nullable()->index('ot_requests_site_idx');
            // FM-07: the PO / work order / project, or else a description of the activities.
            $table->unsignedBigInteger('work_order_id')->nullable()->index('ot_requests_work_order_idx');
            $table->text('activity_description')->nullable();
            // Why it's needed (requested before the work: the reason for urgency).
            $table->text('reason')->nullable();
            // The supervisor's answers (FM-07): could it wait for normal hours; will it be on the client's timesheet.
            $table->boolean('can_postpone')->nullable();
            $table->boolean('on_client_timesheet')->nullable();
            // Requested before the work: the hours actually worked, confirmed afterwards (or from the timesheet).
            $table->decimal('actual_hours', 5, 2)->nullable();
            $table->boolean('requested_in_advance')->default(false);
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
        Schema::dropIfExists('overtime_request_groups');
        Schema::dropIfExists('overtime_types');
    }
};
