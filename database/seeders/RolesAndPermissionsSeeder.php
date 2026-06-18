<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
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
        $email  = config('kazi360.system_user');
        $permissions = json_decode(file_get_contents(database_path('seed-data/roles-and-permissions.json')),
            false, 512, JSON_THROW_ON_ERROR);

        foreach ($permissions as $permission) {
            foreach ($permission->roles as $roleItem) {
                $role = Role::firstOrCreate(
                    [
                        'name' => $roleItem,
                        'guard_name' => 'web',
                    ],
                    [
                        'uuid' => Str::uuid(),
                    ]
                );

                foreach ($permission->permissions as $item) {
                    $rolePermission = Permission::firstOrCreate(
                        [
                            'name' => $item,
                            'group' => $permission->group,
                            'guard_name' => 'web',
                        ],
                        [
                            'uuid' => Str::uuid(),
                        ]
                    );

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

        $user = User::query()->where('username', $email)->first();
        $user?->assignRole($superAdminRole);
    }
}
