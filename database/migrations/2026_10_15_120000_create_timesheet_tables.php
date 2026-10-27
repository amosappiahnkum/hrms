<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Daily timesheets (FM-06): one per employee and pay month, a row per day of the pay period, approved through the
 * timesheet workflow; once approved they feed time inputs, days worked and overtime hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'timesheets_employee_fk')->cascadeOnDelete();
            // The pay month and the dates it covers (from the pay period).
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('period_start');
            $table->date('period_end');
            // staff (FM-06) | freelance (one per client / work order in a month)
            $table->string('template', 20)->default('staff');
            // What makes a sheet unique in its month: "staff", or the freelance sheet's work order / client.
            $table->string('scope', 40)->default('staff');
            $table->foreignId('client_id')->nullable()->constrained(indexName: 'timesheets_client_fk')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained(indexName: 'timesheets_work_order_fk')->nullOnDelete();
            $table->text('task_description')->nullable();
            // Freelance: the client's representative (or supervisor) who signed it, usually not a user.
            $table->string('client_rep_name')->nullable();
            $table->date('client_signed_on')->nullable();
            $table->string('client_signoff_path')->nullable();
            // draft | submitted | approved | rejected
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            // FM-06 "Rejected Date and Time" and "Reason for Rejection".
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            // Per column: hours summed or days ticked; plus days worked and leave days.
            $table->json('totals')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'timesheets_creator_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'year', 'month', 'scope'], 'timesheets_employee_month_unique');
            $table->index(['year', 'month', 'status'], 'timesheets_period_idx');
        });

        Schema::create('timesheet_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_id')->constrained(indexName: 'timesheet_days_sheet_fk')->cascadeOnDelete();
            $table->date('date');
            // Keyed by column (setting payroll.timesheet_columns): hours, or true for a ticked day.
            // Staff sheets: worked out from the day's entries. Freelance sheets: as filled in.
            $table->json('values')->nullable();
            // Freelance sheets: the site; staff sheets: the first entry's (the entries have each one).
            $table->foreignId('work_site_id')->nullable()->constrained(indexName: 'timesheet_days_site_fk')->nullOnDelete();
            // From approved leave (not edited on the sheet).
            $table->boolean('is_leave')->default(false);
            $table->foreignId('leave_request_id')->nullable()->constrained(indexName: 'timesheet_days_leave_fk')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['timesheet_id', 'date'], 'timesheet_days_unique');
        });

        // Staff sheets: what was worked on a day, one entry per site (or stretch of time). The day's normal and
        // overtime hours are worked out from them; an entry for approved overtime is linked to its request.
        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('timesheet_day_id')->constrained(indexName: 'timesheet_entries_day_fk')->cascadeOnDelete();
            $table->foreignId('work_site_id')->nullable()->constrained(indexName: 'timesheet_entries_site_fk')->nullOnDelete();
            $table->decimal('hours', 5, 2)->default(0);
            // Optional: with times, hours outside the working day are overtime (an end before the start is the next day).
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('break_minutes')->nullable();
            // The columns that aren't worked out (ticks such as offshore, other hours), keyed by column.
            $table->json('values')->nullable();
            // Overtime approved in advance: planned until the employee confirms it was worked.
            $table->foreignId('overtime_request_id')->nullable()->constrained(indexName: 'timesheet_entries_ot_fk')->nullOnDelete();
            $table->boolean('planned')->default(false);
            $table->string('notes', 255)->nullable();
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // What an approved timesheet fed into pay, so re-approving it replaces rather than adds.
        Schema::table('time_inputs', function (Blueprint $table) {
            $table->foreignId('timesheet_id')->nullable()->constrained(indexName: 'time_inputs_timesheet_fk')->nullOnDelete();
        });
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->foreignId('timesheet_id')->nullable()->constrained(indexName: 'ot_requests_timesheet_fk')->nullOnDelete();
        });

        // Timekeepers and HR fill in, check and import timesheets for others.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->where('name', 'manage-timesheets')->where('guard_name', 'web')->first();
        if (!$permission) {
            $permission = new Permission();
            $permission->name = 'manage-timesheets';
            $permission->guard_name = 'web';
            $permission->group = 'Payroll';
            $permission->uuid = (string) Str::uuid();
            $permission->save();
        }
        Role::query()->where('guard_name', 'web')->where('name', 'hr')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $this->dropForeignKeys('overtime_requests', ['ot_requests_timesheet_fk']);
        $this->dropForeignKeys('time_inputs', ['time_inputs_timesheet_fk']);
        foreach (['overtime_requests', 'time_inputs'] as $table) {
            if (Schema::hasColumn($table, 'timesheet_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('timesheet_id'));
            }
        }
        Schema::dropIfExists('timesheet_entries');
        Schema::dropIfExists('timesheet_days');
        Schema::dropIfExists('timesheets');
        Permission::query()->where('name', 'manage-timesheets')->delete();
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
