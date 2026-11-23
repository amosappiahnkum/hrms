<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Employee\WorkEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class WorkEmailTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
    }

    /** A staff member whose username is their email, as accounts are provisioned. */
    private function staff(string $email = 'ama.owusu@ttu.edu.gh'): User
    {
        $user = $this->userWithRole('staff');
        $user->forceFill(['email' => $email, 'username' => $email])->save();
        $user->employee->contactDetail->update(['work_email' => $email]);

        return $user->fresh();
    }

    private function contact(User $user, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->putJson("/api/v1/employees/{$user->employee->uuid}/contact",
            $data + ['employee_uuid' => $user->employee->uuid]);
    }

    public function test_hr_changing_the_work_email_changes_the_login_email_and_username(): void
    {
        $staff = $this->staff();
        Sanctum::actingAs($this->userWithRole('hr'));

        $this->contact($staff, ['work_email' => ' Ama.Mensah@TTU.edu.gh', 'city' => 'Takoradi'])->assertOk();

        $staff->refresh();
        $this->assertSame('ama.mensah@ttu.edu.gh', $staff->email);
        $this->assertSame('ama.mensah@ttu.edu.gh', $staff->username);
        $this->assertSame('ama.mensah@ttu.edu.gh', $staff->employee->contactDetail->work_email);
        $this->assertSame('Takoradi', $staff->employee->contactDetail->city);
    }

    public function test_a_username_that_is_not_the_email_stays(): void
    {
        $staff = $this->staff();
        $staff->forceFill(['username' => 'ama.owusu'])->save();
        Sanctum::actingAs($this->userWithRole('hr'));

        $this->contact($staff, ['work_email' => 'ama.mensah@ttu.edu.gh'])->assertOk();

        $this->assertSame('ama.owusu', $staff->fresh()->username);
    }

    public function test_staff_cannot_change_their_work_email(): void
    {
        $staff = $this->staff();
        Sanctum::actingAs($staff);

        $this->contact($staff, ['work_email' => 'me@gmail.com'])
            ->assertStatus(422)->assertJsonValidationErrors('work_email', 'errors');

        $this->assertSame('ama.owusu@ttu.edu.gh', $staff->fresh()->email);
    }

    public function test_staff_can_save_other_contact_fields_with_their_work_email_unchanged(): void
    {
        $staff = $this->staff();
        Sanctum::actingAs($staff);

        // The form sends the stored work email back, in whatever case.
        $this->contact($staff, ['work_email' => 'AMA.OWUSU@ttu.edu.gh', 'city' => 'Accra'])->assertOk();

        $this->assertSame('ama.owusu@ttu.edu.gh', $staff->fresh()->email);
        $this->assertNull($staff->employee->contactDetail->fresh()->city, 'staff changes wait for approval');
    }

    public function test_an_email_someone_else_uses_is_refused(): void
    {
        $staff = $this->staff();
        $this->staff('kofi.boateng@ttu.edu.gh');
        Sanctum::actingAs($this->userWithRole('hr'));

        $this->contact($staff, ['work_email' => 'Kofi.Boateng@ttu.edu.gh'])
            ->assertStatus(422)->assertJsonValidationErrors('work_email', 'errors');

        $this->assertSame('ama.owusu@ttu.edu.gh', $staff->fresh()->email);
    }

    public function test_changing_an_employee_users_email_changes_their_work_email(): void
    {
        $staff = $this->staff();

        app(WorkEmail::class)->changeForUser($staff, 'ama.mensah@ttu.edu.gh');

        $this->assertSame('ama.mensah@ttu.edu.gh', $staff->employee->contactDetail->fresh()->work_email);
        $this->assertSame('ama.mensah@ttu.edu.gh', $staff->fresh()->username);
    }

    public function test_update_mail_moves_the_existing_account_instead_of_creating_another(): void
    {
        Notification::fake();
        $staff = $this->staff();
        $admin = $this->userWithRole('super-admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/update-mail', [
            'id' => $staff->employee->contactDetail->id,
            'work_email' => 'ama.mensah@ttu.edu.gh',
        ])->assertOk();

        $this->assertSame(1, User::where('employee_id', $staff->employee_id)->count());
        $this->assertSame('ama.mensah@ttu.edu.gh', $staff->fresh()->email);
    }

    public function test_sync_command_makes_the_contact_copy_follow_the_login_email(): void
    {
        $staff = $this->staff();
        $staff->employee->contactDetail->update(['work_email' => 'old.address@ttu.edu.gh']);

        $this->artisan('employees:sync-work-emails')->assertSuccessful();
        $this->assertSame('old.address@ttu.edu.gh', $staff->employee->contactDetail->fresh()->work_email, 'reports only');

        $this->artisan('employees:sync-work-emails --apply')->assertSuccessful();
        $this->assertSame('ama.owusu@ttu.edu.gh', $staff->employee->contactDetail->fresh()->work_email);
    }
}
