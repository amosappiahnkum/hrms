<?php

namespace Tests\Feature;

use App\Models\Recruitment\Candidate;
use App\Models\SelfService\Award;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccess(['employees.enabled', 'self_service.enabled', 'self_service.awards']);
    }

    public function test_employee_edits_record_who_changed_what_from_and_to(): void
    {
        $employee = $this->userWithRole('staff')->employee;
        $hr = $this->userWithRole('hr');
        Sanctum::actingAs($hr);

        $this->putJson("/api/v1/employees/{$employee->uuid}", [
            'first_name'      => 'Renamed',
            'last_name'       => $employee->last_name,
            'department_uuid' => $employee->department->uuid,
            'dob'             => '1990-01-01',
            'gender'          => 'Male',
            'job_type'        => 'Full Time',
            'marital_status'  => 'Single',
            'qualification'   => 'BSc',
        ])->assertSuccessful();

        $entry = Activity::where('subject_type', $employee->getMorphClass())
            ->where('subject_id', $employee->id)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($hr->id, (int) $entry->causer_id);
        $this->assertSame('Renamed', $entry->properties['attributes']['first_name']);
        $this->assertSame($employee->first_name, $entry->properties['old']['first_name']);
    }

    public function test_self_service_records_are_audited_on_create_update_and_delete(): void
    {
        $staff = $this->userWithRole('staff');
        $award = Award::create(['title' => 'A', 'employee_id' => $staff->employee_id]);
        $award->update(['title' => 'B']);
        $award->delete();

        $events = Activity::where('subject_type', $award->getMorphClass())->where('subject_id', $award->id)->pluck('event');

        $this->assertEquals(['created', 'updated', 'deleted'], $events->all());
    }

    public function test_passwords_never_reach_the_model_audit_log(): void
    {
        $user = $this->userWithRole('staff');
        $user->update(['password' => bcrypt('new-secret')]);

        Candidate::create([
            'first_name' => 'Cand', 'last_name' => 'Idate', 'email' => 'cand@example.test', 'password' => bcrypt('x'),
        ]);

        foreach (Activity::all() as $entry) {
            $this->assertArrayNotHasKey('password', $entry->properties['attributes'] ?? []);
            $this->assertArrayNotHasKey('password', $entry->properties['old'] ?? []);
        }
    }

    public function test_write_requests_are_recorded_with_secrets_redacted_including_denied_attempts(): void
    {
        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'secret', 'password' => 'N3w-passw0rd!', 'password_confirmation' => 'N3w-passw0rd!',
        ]);
        $this->deleteJson('/api/v1/employees/' . $this->userWithRole('staff')->employee->uuid)->assertForbidden();

        $requests = Activity::inLog('request')->where('causer_id', $staff->id)->get();

        $password = $requests->firstWhere('properties.path', '/api/v1/auth/change-password');
        $this->assertNotNull($password);
        $this->assertSame('[redacted]', $password->properties['input']['password']);
        $this->assertSame('[redacted]', $password->properties['input']['current_password']);
        $this->assertStringNotContainsString('N3w-passw0rd!', $password->properties->toJson());

        $denied = $requests->firstWhere('properties.method', 'DELETE');
        $this->assertSame(403, $denied->properties['status']);
    }

    public function test_plain_reads_are_not_recorded_but_exports_are(): void
    {
        Sanctum::actingAs($this->userWithRole('hr'));

        $this->getJson('/api/v1/employees');
        $this->assertSame(0, Activity::inLog('request')->count());

        $this->get('/api/v1/employees?export=1');
        $this->assertSame(1, Activity::inLog('request')->count());
    }

    public function test_role_changes_record_what_was_added_and_removed(): void
    {
        $target = $this->userWithRole('staff');
        $admin = $this->userWithRole('super-admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/user-management/{$target->uuid}/roles", ['roles' => ['hr']])->assertOk();

        $entry = Activity::inLog('security')->where('event', 'roles_changed')->firstOrFail();

        $this->assertSame($admin->id, (int) $entry->causer_id);
        $this->assertSame($target->id, (int) $entry->subject_id);
        $this->assertEquals(['hr'], $entry->properties['added']);
        $this->assertEquals(['staff'], $entry->properties['removed']);
    }

    public function test_direct_permission_changes_are_recorded(): void
    {
        $target = $this->userWithRole('staff');
        Sanctum::actingAs($this->userWithRole('super-admin'));

        $this->postJson("/api/v1/user-management/{$target->uuid}/permissions", ['permissions' => ['view-employee']])
            ->assertOk();

        $entry = Activity::inLog('security')->where('event', 'permissions_changed')->firstOrFail();
        $this->assertEquals(['view-employee'], $entry->properties['added']);
    }

    public function test_failed_sign_ins_are_recorded_without_the_password(): void
    {
        $user = $this->userWithRole('staff');

        $this->postJson('/api/v1/login', ['username' => $user->username, 'password' => 'wrong-guess']);
        $this->postJson('/api/v1/login', ['username' => 'nobody-here', 'password' => 'wrong-guess']);

        $failed = Activity::inLog('auth')->where('event', 'login_failed')->get();

        $this->assertCount(2, $failed);
        $this->assertSame($user->id, (int) $failed[0]->causer_id);
        $this->assertTrue($failed[0]->properties['account_exists']);
        $this->assertFalse($failed[1]->properties['account_exists']);
        $this->assertStringNotContainsString('wrong-guess', Activity::all()->toJson());
    }

    public function test_impersonation_start_and_end_are_recorded(): void
    {
        $admin = $this->userWithRole('super-admin');
        $target = $this->userWithRole('staff');

        // Impersonation is session-based: make the test client a stateful SPA origin.
        config(['sanctum.stateful' => ['localhost']]);

        $this->actingAs($admin, 'web')
            ->withHeader('Referer', 'http://localhost/')
            ->postJson('/api/v1/auth/impersonate', ['employee_uuid' => $target->employee->uuid])
            ->assertOk();

        // The test client doesn't carry the session across requests, so resume as the impersonated user.
        $this->actingAs($target, 'web')
            ->withSession(['impersonating_original_id' => $admin->id])
            ->withHeader('Referer', 'http://localhost/')
            ->postJson('/api/v1/auth/stop-impersonating')
            ->assertOk();

        $started = Activity::inLog('security')->where('event', 'impersonation_started')->firstOrFail();
        $ended = Activity::inLog('security')->where('event', 'impersonation_ended')->firstOrFail();

        $this->assertSame($admin->id, (int) $started->causer_id);
        $this->assertSame($target->id, (int) $started->subject_id);
        $this->assertSame($admin->id, (int) $ended->causer_id);

        $during = Activity::inLog('request')->where('properties->path', '/api/v1/auth/stop-impersonating')->firstOrFail();
        $this->assertSame($admin->id, (int) $during->properties['impersonator_id']);
    }
}
