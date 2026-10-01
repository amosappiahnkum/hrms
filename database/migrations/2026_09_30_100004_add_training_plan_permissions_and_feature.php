<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Training Plan permissions. (Its feature flag comes from SettingSeeder, run on every deploy.)
 * Validators and approvers are named individuals, so validate/approve are not granted to any
 * role here — assign them to people through user management (super-admin passes regardless).
 */
return new class extends Migration {
    /** permission => roles */
    private const PERMISSIONS = [
        'view-training-plan'     => ['hr', 'training_officer'],
        'prepare-training-plan'  => ['hr', 'training_officer'],
        'validate-training-plan' => [],
        'approve-training-plan'  => [],
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
                $permission->group = 'Training Plan';
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
