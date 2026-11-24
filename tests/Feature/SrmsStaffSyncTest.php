<?php

namespace Tests\Feature;

use App\Jobs\PushStaffToSrms;
use App\Models\Config\Department;
use App\Models\SelfService\Employee;
use App\Services\SettingService;
use App\Services\Srms\SrmsStaffSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SrmsStaffSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['srms.url' => 'https://srms.test/api/internal/v1', 'srms.key' => 'hrms-key']);
        app(SettingService::class)->set('features.integrations.srms', true, 'integrations'); // TTU's switch
        Queue::fake(); // adding the employee below queues a push too
    }

    private function employee(): Employee
    {
        $employee = Employee::create([
            'staff_id' => '755001', 'first_name' => 'Kofi', 'last_name' => 'Mensah', 'title' => 'Dr.',
            'department_id' => Department::create(['name' => 'ICT'])->id,
        ]);
        $employee->contactDetail()->create(['work_email' => 'k.mensah@ttu.edu.gh', 'telephone' => '0201234567']);

        return $employee->fresh();
    }

    public function test_sends_what_srms_uses_and_treats_someone_not_in_srms_as_skipped(): void
    {
        Http::fake([
            'srms.test/*' => Http::sequence()->push(['message' => 'Staff details updated.'])->push(['message' => 'Staff not found.'], 404),
        ]);
        $employee = $this->employee();
        $sync = app(SrmsStaffSync::class);

        $this->assertSame('updated', $sync->push($employee));
        $this->assertSame('not_in_srms', $sync->push($employee));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://srms.test/api/internal/v1/staff/bio-data'
            && $request->hasHeader('Authorization', 'Bearer hrms-key')
            && $request['staff_id'] === '755001' && $request['work_email'] === 'k.mensah@ttu.edu.gh'
            && $request['telephone'] === '0201234567' && $request['active'] === true && $request['biodata_updated'] === false
            && ! isset($request['ssnit_number'], $request['dob']));
    }

    public function test_queues_a_push_when_a_name_or_contact_changes_or_someone_leaves(): void
    {
        $employee = $this->employee();
        Queue::assertPushed(PushStaffToSrms::class, 2); // a new employee, and their contact details
        Queue::fake();

        $employee->update(['first_name' => 'Kwame']);
        $employee->contactDetail->update(['telephone' => '0247654321']);
        $employee->delete();
        Queue::assertPushed(PushStaffToSrms::class, 3);

        // Nothing SRMS uses changed: nothing sent.
        Queue::fake();
        $employee->contactDetail->update(['address' => 'Takoradi']);
        Queue::assertNothingPushed();
    }

    public function test_sends_nothing_for_other_organisations_or_until_srms_is_set_up(): void
    {
        // Another organisation: the switch is off (its default), even with an address set.
        app(SettingService::class)->set('features.integrations.srms', false, 'integrations');
        $this->employee()->update(['first_name' => 'Kwame']);
        Queue::assertNothingPushed();
        $this->artisan('srms:push-staff')->assertFailed();

        app(SettingService::class)->set('features.integrations.srms', true, 'integrations');
        config(['srms.url' => null]);
        $this->assertFalse(SrmsStaffSync::enabled());
    }

    public function test_the_first_fill_waits_when_srms_says_too_many_and_sends_again(): void
    {
        Sleep::fake();
        $this->employee();
        Http::fake([
            'srms.test/*' => Http::sequence()
                ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '7'])
                ->push(['message' => 'Staff details updated.']),
        ]);

        $this->artisan('srms:push-staff')
            ->expectsOutputToContain('Updated in SRMS: 1. Not in SRMS (skipped): 0. Failed: 0.')
            ->assertSuccessful();
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 7.0, 1);
        Http::assertSentCount(2);
    }
}
