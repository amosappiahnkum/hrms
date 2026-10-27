<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Super-admins manage roles and permissions; built-in ones are protected. */
class AccessControlTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        $this->admin = $this->userWithRole('super-admin');
        Sanctum::actingAs($this->admin);
    }

    public function test_only_super_admins_manage_access(): void
    {
        Sanctum::actingAs($this->userWithRole('hr'));
        $this->getJson('/api/v1/access')->assertForbidden();
        $this->postJson('/api/v1/access/roles', ['name' => 'operations-manager'])->assertForbidden();
    }

    public function test_roles_are_listed_with_their_permissions_grouped(): void
    {
        $data = $this->getJson('/api/v1/access')->assertOk()->json('data');

        $hr = collect($data['roles'])->firstWhere('name', 'hr');
        $this->assertFalse($hr['is_custom']);
        $this->assertSame(Role::findByName('hr', 'web')->permissions->pluck('name')->sort()->values()->all(), $hr['permissions']);
        $this->assertTrue(collect($data['roles'])->firstWhere('name', 'super-admin')['locked']);
        $this->assertContains('Payroll', collect($data['permissions'])->pluck('group')->all());
        $this->assertSame(Permission::where('guard_name', 'web')->count(), collect($data['permissions'])->sum(fn ($g) => count($g['permissions'])));
    }

    public function test_a_custom_role_is_created_configured_and_removed(): void
    {
        $this->postJson('/api/v1/access/roles', ['name' => 'Operations Manager'])->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/access/roles', ['name' => 'hr'])->assertJsonValidationErrors('name');

        $uuid = $this->postJson('/api/v1/access/roles', ['name' => 'operations-manager', 'permissions' => ['view-payroll']])->assertCreated()
            ->assertJsonPath('data.is_custom', true)->assertJsonPath('data.permissions', ['view-payroll'])->json('data.uuid');

        $this->putJson("/api/v1/access/roles/{$uuid}", ['name' => 'ops-manager', 'permissions' => ['view-payroll', 'approve-payroll']])->assertOk()
            ->assertJsonPath('data.name', 'ops-manager')->assertJsonPath('data.permissions', ['approve-payroll', 'view-payroll']);
        $this->assertTrue(User::find($this->userWithRole('staff')->id)->assignRole('ops-manager')->hasPermissionTo('approve-payroll'));

        // Still in use: refused.
        $this->deleteJson("/api/v1/access/roles/{$uuid}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'still have this role'));
        \Illuminate\Support\Facades\DB::table(config('permission.table_names.model_has_roles'))->where('role_id', Role::findByName('ops-manager', 'web')->id)->delete();
        $this->deleteJson("/api/v1/access/roles/{$uuid}")->assertOk();
        $this->assertNull(Role::where('name', 'ops-manager')->first());
    }

    public function test_built_in_roles_keep_their_names_but_their_permissions_can_change(): void
    {
        $hr = Role::findByName('hr', 'web');
        $this->putJson("/api/v1/access/roles/{$hr->uuid}", ['name' => 'people-team'])->assertStatus(422);
        $this->deleteJson("/api/v1/access/roles/{$hr->uuid}")->assertStatus(422);

        $this->putJson("/api/v1/access/roles/{$hr->uuid}", ['permissions' => [...$hr->permissions->pluck('name'), 'manage-timesheets']])->assertOk();
        $this->assertTrue($hr->fresh()->hasPermissionTo('manage-timesheets'));

        $super = Role::findByName('super-admin', 'web');
        $this->putJson("/api/v1/access/roles/{$super->uuid}", ['permissions' => []])->assertStatus(422);
    }

    public function test_custom_permissions_can_be_added_and_removed_built_in_ones_cant(): void
    {
        $uuid = $this->postJson('/api/v1/access/permissions', ['name' => 'approve-site-work', 'group' => 'Operations'])->assertCreated()->json('data.uuid');
        $this->postJson('/api/v1/access/permissions', ['name' => 'approve-site-work', 'group' => 'Operations'])->assertJsonValidationErrors('name');
        $this->deleteJson('/api/v1/access/permissions/' . Permission::findByName('configure-payroll', 'web')->uuid)->assertStatus(422);

        $this->deleteJson("/api/v1/access/permissions/{$uuid}")->assertOk();
        $this->assertNull(Permission::where('name', 'approve-site-work')->first());
    }
}
