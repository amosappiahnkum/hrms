<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use JsonException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     * @throws JsonException
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permissions = json_decode(file_get_contents(database_path('seed-data/roles-and-permissions.json')),
            false, 512, JSON_THROW_ON_ERROR);

        foreach ($permissions as $permission) {
            foreach ($permission->roles as $roleItem) {
                $role = Role::query()->where('name', $roleItem)->first();

                if (!$role) {
                    $role = new Role();
                    $role->name = $roleItem;
                    $role->uuid = Str::uuid();
                    $role->save();
                }

                foreach ($permission->permissions as $item) {
                    $rolePermission = Permission::query()
                        ->where('name', $item)
                        ->where('group', $permission->group)->first();

                    if (!$rolePermission) {
                        $rolePermission = new Permission();
                        $rolePermission->name = $item;
                        $rolePermission->group = $permission->group;
                        $rolePermission->uuid = Str::uuid();
                        $rolePermission->save();
                    }

                    $role->givePermissionTo($rolePermission);
                }
            }
        }

        $superAdminRole = Role::query()->where('name', 'super-admin')->first();

        if (!$superAdminRole) {
            $superAdminRole = new Role();
            $superAdminRole->name = 'super-admin';
            $superAdminRole->uuid = Str::uuid();
            $superAdminRole->save();
        }
        $superAdminRole->givePermissionTo(Permission::all());

        $user = User::query()->where('username', 'israelnkum')->first();
        $user?->assignRole($superAdminRole);
    }
}
