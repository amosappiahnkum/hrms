<?php

namespace Tests\Feature;

use App\Enums\Statuses;
use App\Models\Config\Department;
use App\Models\EmployeeSupervisor;
use App\Models\InformationUpdate;
use App\Models\SelfService\Award;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private const FEATURES = [
        'employees.enabled',
        'self_service.enabled',
        'self_service.awards',
        'information_updates.enabled',
        'leave.enabled',
        'leave.hr_approval',
        'recruitment.enabled',
        'leave.self_service',
        'certifications.enabled',
        'direct_reports.enabled',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccess(self::FEATURES);
    }

    private function pendingUpdateFor(User $requester): InformationUpdate
    {
        return InformationUpdate::create([
            'information_id'   => $requester->employee_id,
            'information_type' => 'Employee',
            'type'             => 'update',
            'status'           => 'pending',
            'old_info'         => ['first_name' => 'Old'],
            'new_info'         => ['first_name' => 'New'],
            'requested_by'     => $requester->id,
        ]);
    }

    public function test_staff_cannot_list_or_export_employees(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->getJson('/api/v1/employees?export=1')->assertForbidden();
    }

    public function test_hr_can_list_employees(): void
    {
        Sanctum::actingAs($this->userWithRole('hr'));

        $this->getJson('/api/v1/employees')->assertOk();
    }

    public function test_staff_cannot_archive_an_employee(): void
    {
        $victim = $this->userWithRole('staff')->employee;
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->deleteJson("/api/v1/employees/{$victim->uuid}")->assertForbidden();
        $this->assertNotSoftDeleted($victim);
    }

    public function test_staff_cannot_assign_permissions(): void
    {
        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/common/permissions/assign', [
            'employeeId'  => $staff->employee_id,
            'permissions' => [],
        ])->assertForbidden();
    }

    public function test_staff_cannot_edit_other_user_accounts(): void
    {
        $other = $this->userWithRole('staff');
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->putJson("/api/v1/users/{$other->id}", ['email' => 'attacker@example.test'])->assertForbidden();
        $this->assertNotSame('attacker@example.test', $other->fresh()->email);
    }

    public function test_staff_cannot_relink_another_employees_email(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->postJson('/api/v1/update-mail', ['id' => 1, 'work_email' => 'attacker@example.test'])
            ->assertForbidden();
    }

    public function test_staff_cannot_approve_information_updates(): void
    {
        $requester = $this->userWithRole('staff');
        $update = $this->pendingUpdateFor($requester);

        // Even the requester must not be able to approve their own change.
        Sanctum::actingAs($requester);

        $this->postJson("/api/v1/approvals/{$update->uuid}/approve")->assertForbidden();
        $this->postJson("/api/v1/approvals/{$update->uuid}/reject")->assertForbidden();
        $this->getJson('/api/v1/approvals')->assertForbidden();
        $this->assertSame(Statuses::PENDING, $update->fresh()->status);
    }

    public function test_staff_can_only_view_their_own_update_requests(): void
    {
        $requester = $this->userWithRole('staff');
        $update = $this->pendingUpdateFor($requester);

        Sanctum::actingAs($this->userWithRole('staff'));
        $this->getJson("/api/v1/approvals/{$update->uuid}")->assertForbidden();

        Sanctum::actingAs($requester);
        $this->getJson("/api/v1/approvals/{$update->uuid}")->assertOk();
    }

    public function test_staff_cannot_make_hr_leave_decisions(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->postJson('/api/v1/hr-change-leave-status', [])->assertForbidden();
        $this->getJson('/api/v1/leave-management/analytics')->assertForbidden();
    }

    public function test_staff_can_only_read_their_own_profile_sections(): void
    {
        $staff = $this->userWithRole('staff');
        $other = $this->userWithRole('staff')->employee;
        Sanctum::actingAs($staff);

        $this->getJson("/api/v1/employees/{$other->uuid}")->assertForbidden();
        $this->getJson("/api/v1/employees/{$other->uuid}/contact")->assertForbidden();
        $this->getJson("/api/v1/employees/{$other->uuid}/job-detail")->assertForbidden();

        $this->getJson("/api/v1/employees/{$staff->employee->uuid}")->assertOk();
        $this->getJson("/api/v1/employees/{$staff->employee->uuid}/contact")->assertOk();
        $this->getJson("/api/v1/employees/{$staff->employee->uuid}/stats")->assertOk();
    }

    public function test_staff_cannot_touch_another_employees_self_service_records(): void
    {
        $other = $this->userWithRole('staff')->employee;
        $award = Award::create(['title' => 'Theirs', 'employee_id' => $other->id]);
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->getJson("/api/v1/awards/{$award->uuid}")->assertForbidden();
        $this->putJson("/api/v1/awards/{$award->uuid}", ['title' => 'Mine now'])->assertForbidden();
        $this->deleteJson("/api/v1/awards/{$award->uuid}")->assertForbidden();
        $this->getJson("/api/v1/awards?employee_uuid={$other->uuid}")->assertForbidden();
        $this->postJson('/api/v1/awards', ['employee_uuid' => $other->uuid, 'title' => 'x'])->assertForbidden();
    }

    public function test_unfiltered_self_service_listing_is_scoped_to_the_caller(): void
    {
        $other = $this->userWithRole('staff')->employee;
        Award::create(['title' => 'Theirs', 'employee_id' => $other->id]);

        $staff = $this->userWithRole('staff');
        Award::create(['title' => 'Mine', 'employee_id' => $staff->employee_id]);
        Sanctum::actingAs($staff);

        $titles = collect($this->getJson('/api/v1/awards')->assertOk()->json('data'))->pluck('title');

        $this->assertEquals(['Mine'], $titles->all());
    }

    public function test_hr_can_manage_any_employees_records(): void
    {
        $other = $this->userWithRole('staff')->employee;
        $award = Award::create(['title' => 'Theirs', 'employee_id' => $other->id]);
        Sanctum::actingAs($this->userWithRole('hr'));

        $this->getJson("/api/v1/awards/{$award->uuid}")->assertOk();
        $this->getJson("/api/v1/employees/{$other->uuid}/contact")->assertStatus(200);
    }

    public function test_permissions_can_be_granted_to_individual_users(): void
    {
        $clerk = $this->userWithRole('staff');
        $clerk->givePermissionTo('view-employee');
        Sanctum::actingAs($clerk);

        $this->getJson('/api/v1/employees')->assertOk();
        $this->getJson('/api/v1/employees?export=1')->assertForbidden();
        $this->deleteJson('/api/v1/employees/' . $this->userWithRole('staff')->employee->uuid)->assertForbidden();
    }

    public function test_recruitment_permissions_are_enforced_per_action(): void
    {
        $viewer = $this->userWithRole('staff');
        $viewer->givePermissionTo('view-job-opening');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/recruitment/job-openings')->assertOk();
        $this->postJson('/api/v1/recruitment/job-openings', [])->assertForbidden();
        $this->getJson('/api/v1/recruitment/candidates')->assertForbidden();
    }

    public function test_super_admin_passes_permission_checks_without_direct_grants(): void
    {
        $admin = $this->userWithRole('super-admin');
        $admin->syncPermissions([]);
        $admin->roles()->first()->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/employees')->assertOk();
        $this->assertTrue($admin->fresh()->can('resolve-support-tickets'));
    }

    public function test_permission_migration_grants_existing_roles_their_current_access(): void
    {
        $officer = Role::firstOrCreate(
            ['name' => 'training_officer', 'guard_name' => 'web'],
            ['uuid' => Str::uuid()]
        );
        $officer->syncPermissions([]);

        $migration = require database_path('migrations/2026_09_30_000001_sync_route_permissions.php');
        $migration->up();
        $migration->up(); // idempotent

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(Role::findByName('hr')->hasPermissionTo('finalize-leave'));
        $this->assertTrue(Role::findByName('hr')->hasPermissionTo('manage-training'));
        $this->assertTrue($officer->fresh()->hasPermissionTo('manage-training'));
        $this->assertFalse($officer->fresh()->hasPermissionTo('finalize-leave'));
        $this->assertFalse(Role::findByName('staff')->hasPermissionTo('view-employee'));
        $this->assertFalse(Role::findByName('hod')->hasPermissionTo('finalize-leave'));
        $this->assertSame(1, \Spatie\Permission\Models\Permission::where('name', 'finalize-leave')->count());
    }

    public function test_edit_employee_permission_saves_changes_directly_instead_of_queueing_approval(): void
    {
        $other = $this->userWithRole('staff')->employee;
        $award = Award::create(['title' => 'Before', 'employee_id' => $other->id]);

        $editor = $this->userWithRole('staff');
        $editor->givePermissionTo('edit-employee');
        Sanctum::actingAs($editor);

        $this->putJson("/api/v1/awards/{$award->uuid}", [
            'employee_uuid' => $other->uuid, 'title' => 'After', 'year' => 2025, 'giving_by' => 'TTU',
        ])->assertOk();

        $this->assertSame('After', $award->fresh()->title);
        $this->assertSame(0, InformationUpdate::count());
    }

    public function test_staff_edits_to_their_own_records_still_go_through_approval(): void
    {
        $staff = $this->userWithRole('staff');
        $award = Award::create(['title' => 'Before', 'employee_id' => $staff->employee_id]);
        Sanctum::actingAs($staff);

        $this->putJson("/api/v1/awards/{$award->uuid}", [
            'employee_uuid' => $staff->employee->uuid, 'title' => 'After', 'year' => 2025, 'giving_by' => 'TTU',
        ])->assertOk();

        $this->assertSame('Before', $award->fresh()->title);
        $this->assertSame(1, InformationUpdate::count());
    }

    public function test_viewing_all_leave_requests_requires_view_all_leave(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $this->getJson('/api/v1/leave-requests')->assertForbidden();

        $officer = $this->userWithRole('staff');
        $officer->givePermissionTo('view-all-leave');
        Sanctum::actingAs($officer);
        $this->assertNotSame(403, $this->getJson('/api/v1/leave-requests')->status());
    }

    public function test_certification_management_requires_manage_certifications(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $this->getJson('/api/v1/certifications')->assertForbidden();

        Sanctum::actingAs($this->userWithRole('hr'));
        $this->getJson('/api/v1/certifications')->assertOk();
    }

    public function test_hod_role_keeps_supervisor_rights_through_permissions(): void
    {
        $hod = $this->userWithRole('hod');

        $this->assertTrue($hod->can('approve-leave-request'));
        $this->assertFalse($this->userWithRole('staff')->can('approve-leave-request'));
    }

    public function test_leave_notifications_go_to_permission_holders(): void
    {
        $hr = $this->userWithRole('hr');
        $delegate = $this->userWithRole('staff');
        $delegate->givePermissionTo('finalize-leave');
        $staff = $this->userWithRole('staff');

        $recipients = User::permission('finalize-leave')->pluck('id');

        $this->assertTrue($recipients->contains($hr->id));
        $this->assertTrue($recipients->contains($delegate->id));
        $this->assertFalse($recipients->contains($staff->id));
    }

    public function test_staff_cannot_delete_reporting_lines_or_list_other_supervisors_reports(): void
    {
        $supervisor = $this->userWithRole('staff');
        $report = $this->userWithRole('staff');
        $link = EmployeeSupervisor::create([
            'supervisor_id' => $supervisor->employee_id,
            'employee_id'   => $report->employee_id,
        ]);

        Sanctum::actingAs($this->userWithRole('staff'));
        $this->deleteJson("/api/v1/direct-reports/{$link->id}")->assertForbidden();
        $this->getJson("/api/v1/direct-reports?supervisorId={$supervisor->employee_id}")->assertForbidden();
        $this->assertModelExists($link);

        Sanctum::actingAs($supervisor);
        $this->getJson("/api/v1/direct-reports?supervisorId={$supervisor->employee_id}")->assertOk();

        Sanctum::actingAs($this->userWithRole('hr'));
        $this->deleteJson("/api/v1/direct-reports/{$link->id}")->assertOk();
    }

    private function onboardingPayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'employee_id'   => $user->employee->uuid,
            'first_name'    => $user->employee->first_name,
            'last_name'     => $user->employee->last_name,
            'staff_id'      => 'STF-' . $user->employee_id,
            'department_id' => Department::create(['name' => Str::random(8)])->id,
        ], $overrides);
    }

    public function test_onboarding_can_change_department_and_staff_id_only_once(): void
    {
        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);

        $first = $this->onboardingPayload($staff);
        $this->postJson('/api/v1/employees/update-onboarding', $first)->assertOk();

        $employee = $staff->employee->fresh();
        $this->assertSame($first['staff_id'], $employee->staff_id);
        $this->assertSame($first['department_id'], (int) $employee->department_id);

        $again = $this->onboardingPayload($staff, ['staff_id' => 'CHANGED']);
        $this->postJson('/api/v1/employees/update-onboarding', $again)->assertStatus(422);

        $this->assertSame($first['staff_id'], $staff->employee->fresh()->staff_id);
        $this->assertSame($first['department_id'], (int) $staff->employee->fresh()->department_id);
    }

    public function test_onboarding_cannot_claim_another_employees_staff_id(): void
    {
        $other = $this->userWithRole('staff')->employee;
        $other->update(['staff_id' => 'TAKEN-1']);

        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/employees/update-onboarding', $this->onboardingPayload($staff, ['staff_id' => 'TAKEN-1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_staff_cannot_onboard_someone_else(): void
    {
        $victim = $this->userWithRole('staff');
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->postJson('/api/v1/employees/update-onboarding', $this->onboardingPayload($victim))->assertForbidden();
        $this->assertFalse((bool) $victim->employee->fresh()->onboarding);
    }

    public function test_qr_verification_page_escapes_employee_names(): void
    {
        $employee = $this->userWithRole('staff')->employee;
        $employee->update(['first_name' => '<script>alert(1)</script>']);

        $this->get("/api/scan/{$employee->uuid}")
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_approving_an_already_processed_request_returns_a_readable_conflict(): void
    {
        $requester = $this->userWithRole('staff');
        $update = $this->pendingUpdateFor($requester);
        Sanctum::actingAs($this->userWithRole('hr'));

        $this->postJson("/api/v1/approvals/{$update->uuid}/approve")->assertOk();

        $this->postJson("/api/v1/approvals/{$update->uuid}/approve")
            ->assertStatus(409)
            ->assertJson(['message' => 'This request has already been processed.']);
    }
}
