<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class LeaveRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $role = Role::firstOrCreate(
            [
                'name' => 'hod',
                'guard_name' => 'web',
            ],
            [
                'uuid' => Str::uuid(),
            ]
        );
        $role->givePermissionTo(Permission::query()->where('group', 'Leave Request')->get());
    }
}
