<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Payroll permissions. HR has them all to start; give them to finance or management as needed.
 * Self-service (own payslips, overtime, loans) needs none. Flags and settings come from SettingSeeder.
 */
return new class extends Migration {
    /** permission => roles */
    private const PERMISSIONS = [
        // Statutory rates, pay components, currencies, approval workflows and other payroll settings.
        'configure-payroll' => ['hr'],
        // Employees' pay details and preparing pay runs.
        'prepare-payroll'   => ['hr'],
        // Approving pay runs (a different person from the preparer when the setting asks for it).
        'approve-payroll'   => ['hr'],
        // Read-only: pay details, runs and reports.
        'view-payroll'      => ['hr'],
        // Every overtime request, not only those the user approves.
        'view-overtime'     => ['hr'],
        // Every loan: disbursement, schedules, settlement.
        'manage-loans'      => ['hr'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = Role::query()->where('guard_name', 'web')->get()->keyBy('name');

        foreach (self::PERMISSIONS as $name => $roleNames) {
            $permission = Permission::query()->where('name', $name)->where('guard_name', 'web')->first();

            if (!$permission) {
                $permission = new Permission();
                $permission->name = $name;
                $permission->guard_name = 'web';
                $permission->group = 'Payroll';
                $permission->uuid = (string) Str::uuid();
                $permission->save();
            }

            foreach ($roleNames as $roleName) {
                $roles->get($roleName)?->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions may have been granted to users since; leave them in place.
    }
};
