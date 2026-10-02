<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Training Plan permissions. (Its feature flag comes from SettingSeeder, run on every deploy.)
 * Who validates and approves is set on the approval levels page, which also keeps
 * review-training-plan in step with the people in the levels (it lets them open the plan).
 * Heads of department need no permission: they add trainings for their own departments.
 */
return new class extends Migration {
    /** permission => roles */
    private const PERMISSIONS = [
        'view-training-plan'                => ['hr', 'training_officer'],
        'prepare-training-plan'             => ['hr', 'training_officer'],
        'review-training-plan'              => [],
        'configure-training-plan-approvals' => ['hr'],
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
