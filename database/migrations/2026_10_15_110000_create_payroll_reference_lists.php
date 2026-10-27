<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lists the payroll forms refer to: work sites (where work is done), clients and their work orders / POs, and
 * cost centres (which an employee's pay is charged to; their department when none is set).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sites', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('code', 20);
            $table->string('name');
            // Offshore sites allow offshore work (e.g. offshore days on a timesheet).
            $table->boolean('is_offshore')->default(false);
            $table->boolean('active')->default(true);
            // Typed in by someone filling a timesheet or overtime request: HR keeps, merges or discards it.
            $table->boolean('needs_review')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'work_sites_creator_fk')->nullOnDelete();
            // Merged into another site (then soft deleted).
            $table->foreignId('merged_into_id')->nullable()->constrained('work_sites', indexName: 'work_sites_merged_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Null: internal work (a project with no client).
            $table->foreignId('client_id')->nullable()->constrained(indexName: 'work_orders_client_fk')->nullOnDelete();
            // The PO, work order or project number, e.g. "PO# 4500028551".
            $table->string('number', 50);
            $table->string('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cost_centres', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 20);
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('job_details', function (Blueprint $table) {
            $table->foreignId('cost_centre_id')->nullable()->constrained(indexName: 'job_details_cost_centre_fk')->nullOnDelete();
        });

        // Overtime requests (created earlier) name a site and a work order.
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->foreign('work_site_id', 'ot_requests_site_fk')->references('id')->on('work_sites')->nullOnDelete();
            $table->foreign('work_order_id', 'ot_requests_work_order_fk')->references('id')->on('work_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $this->dropForeignKeys('overtime_requests', ['ot_requests_site_fk', 'ot_requests_work_order_fk']);
        $this->dropForeignKeys('job_details', ['job_details_cost_centre_fk']);
        if (Schema::hasColumn('job_details', 'cost_centre_id')) {
            Schema::table('job_details', fn (Blueprint $table) => $table->dropColumn('cost_centre_id'));
        }
        Schema::dropIfExists('cost_centres');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('work_sites');
    }

    /**
     * Drop foreign keys that exist. This migration was edited after some databases ran it (payroll migrations
     * are rerun), so a key it now adds may never have been made there.
     */
    private function dropForeignKeys(string $table, array $names): void
    {
        $existing = collect(Schema::getForeignKeys($table))->pluck('name');
        $names = array_values(array_filter($names, fn ($n) => $existing->contains($n)));
        if ($names) {
            Schema::table($table, function (Blueprint $t) use ($names) {
                foreach ($names as $name) {
                    $t->dropForeign($name);
                }
            });
        }
    }
};
