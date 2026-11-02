<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Granting authorizations (the authorization register). Recommending needs no permission: anyone
 * who may assess the employee recommends. Management can be given it later; HR has it to start.
 */
return new class extends Migration {
    /** permission => roles */
    private const PERMISSIONS = [
        // Authorize, decline, reinstate and revoke.
        'grant-authorizations' => ['hr'],
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
