<?php

namespace Tests\Concerns;

use App\Models\Config\Department;
use App\Models\Config\Setting;
use App\Models\SelfService\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/** Roles, permissions and feature flags as a fresh install has them, plus user factories. */
trait SetsUpAccess
{
    protected function setUpAccess(array $features): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($features as $feature) {
            Setting::create(['key' => "features.{$feature}", 'value' => true, 'group' => 'features']);
        }
    }

    protected function userWithRole(string $role): User
    {
        // Mirror EmployeeController::store so resources have the relations they expect.
        $employee = Employee::create([
            'first_name'    => Str::random(6),
            'last_name'     => Str::random(6),
            'department_id' => Department::create(['name' => Str::random(8)])->id,
        ]);
        $employee->contactDetail()->create();
        $employee->jobDetail()->create();

        $user = User::create([
            'name'        => $employee->first_name,
            'username'    => Str::lower(Str::random(10)),
            'email'       => Str::lower(Str::random(10)) . '@example.test',
            'password'    => bcrypt('secret'),
            'employee_id' => $employee->id,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
