<?php

namespace Tests\Feature;

use App\Models\Config\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccess([]);
        $this->admin = $this->userWithRole('staff');
        $this->admin->givePermissionTo(['add-department', 'edit-department', 'delete-department']);
        Sanctum::actingAs($this->admin);
    }

    private function create(string $name, ?Department $parent = null): Department
    {
        $uuid = $this->postJson('/api/v1/departments', array_filter([
            'name' => $name,
            'parent_department_id' => $parent?->uuid,
        ]))->assertSuccessful()->json('uuid');

        return Department::where('uuid', $uuid)->firstOrFail();
    }

    public function test_sub_departments_are_listed_under_their_parent_with_a_breadcrumb_path(): void
    {
        $ops = $this->create('Operations');
        $ndt = $this->create('NDT', $ops);
        $offshore = $this->create('NDT Offshore', $ndt);

        $this->getJson('/api/v1/departments?parent_id=root')->assertOk()
            ->assertJsonMissing(['name' => 'NDT']);
        $this->getJson("/api/v1/departments?parent_id={$ops->uuid}")->assertOk()
            ->assertJsonPath('data.0.name', 'NDT')
            ->assertJsonPath('data.0.children_count', 1);

        $this->getJson("/api/v1/departments/{$offshore->uuid}")->assertOk()
            ->assertJsonPath('data.parent_id', $ndt->uuid)
            ->assertJsonPath('path', [['uuid' => $ops->uuid, 'name' => 'Operations'], ['uuid' => $ndt->uuid, 'name' => 'NDT']]);
    }

    public function test_renaming_a_sub_department_keeps_its_parent(): void
    {
        $ops = $this->create('Operations');
        $ndt = $this->create('NDT', $ops);

        $this->patchJson("/api/v1/departments/{$ndt->uuid}", ['name' => 'NDT Services'])->assertOk()
            ->assertJsonPath('parent_id', $ops->uuid);

        // Sending an empty parent moves it to the top level on purpose.
        $this->patchJson("/api/v1/departments/{$ndt->uuid}", ['parent_department_id' => null])->assertOk()
            ->assertJsonPath('parent_id', null);
    }

    public function test_a_department_cannot_be_moved_under_itself_or_its_sub_departments(): void
    {
        $ops = $this->create('Operations');
        $ndt = $this->create('NDT', $ops);

        $this->patchJson("/api/v1/departments/{$ops->uuid}", ['parent_department_id' => $ndt->uuid])->assertStatus(422);
        $this->patchJson("/api/v1/departments/{$ops->uuid}", ['parent_department_id' => $ops->uuid])->assertStatus(422);
        $this->assertNull($ops->fresh()->parent_department_id);
    }

    public function test_deleting_needs_no_employees_or_sub_departments(): void
    {
        $ops = $this->create('Operations');
        $ndt = $this->create('NDT', $ops);

        $this->deleteJson("/api/v1/departments/{$ops->uuid}")->assertStatus(422);
        $this->deleteJson("/api/v1/departments/{$ndt->uuid}")->assertOk();
        $this->assertSoftDeleted($ndt);

        // The name can be used again once deleted.
        $this->create('NDT', $ops);
    }

    public function test_a_name_is_required(): void
    {
        $this->postJson('/api/v1/departments', [])->assertStatus(422)->assertJsonValidationErrors('name');
    }
}
