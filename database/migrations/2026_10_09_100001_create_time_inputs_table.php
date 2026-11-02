<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Time inputs: units per employee per month (offshore days, shifts, call-outs…) priced by a
 * per-unit pay component, approved when the organization asks for it, and paid by a pay run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_inputs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained(indexName: 'time_inputs_employee_fk')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained(indexName: 'time_inputs_component_fk')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('quantity', 8, 2);
            $table->string('notes', 500)->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('entered_by')->nullable()->constrained('users', indexName: 'time_inputs_enterer_fk')->nullOnDelete();
            $table->foreignId('pay_run_id')->nullable()->constrained(indexName: 'time_inputs_run_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['year', 'month', 'pay_component_id'], 'time_inputs_period_idx');
            $table->index(['status', 'pay_run_id'], 'time_inputs_payable_idx');
        });

        // Who enters them (timekeepers, site supervisors); HR to start.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->where('name', 'enter-time-inputs')->where('guard_name', 'web')->first();
        if (!$permission) {
            $permission = new Permission();
            $permission->name = 'enter-time-inputs';
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
        Schema::dropIfExists('time_inputs');
    }
};
