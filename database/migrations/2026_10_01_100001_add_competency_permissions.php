<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Competency matrix permissions, for organisation-wide access from Employee Management. HODs and
 * supervisors need none: they see and assess their own team from self-service.
 * (The feature flag comes from SettingSeeder, run on every deploy.)
 */
return new class extends Migration {
    /** permission => roles */
    private const PERMISSIONS = [
        // Organisation-wide: see everyone's competencies (Employee Management).
        'view-competencies'   => ['hr'],
        // Organisation-wide: rate anyone.
        'assess-competencies' => ['hr'],
        // The competency library and what each position requires.
        'manage-competencies' => ['hr'],
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
                $permission->group = 'Competency';
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
