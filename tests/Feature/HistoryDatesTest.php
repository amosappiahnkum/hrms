<?php

namespace Tests\Feature;

use App\Events\ApprovalRequested;
use App\Models\InformationUpdate;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class HistoryDatesTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_a_previous_position_keeps_the_dates_entered(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');
        $employee = $user->employee;

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/previous-positions', [
            'employee_uuid'   => $employee->uuid,
            'department_uuid' => $employee->department->uuid,
            'position_uuid'   => Position::create(['name' => 'Lecturer'])->uuid,
            'start'           => '2015-03-01',
            'end'             => '2019-08-31',
        ])->assertOk();

        $info = InformationUpdate::where('information_type', 'PreviousPosition')->sole()->new_info;
        $this->assertSame('2015-03-01', $info['start']);
        $this->assertSame('2019-08-31', $info['end']);
    }

    public function test_a_previous_position_requires_a_start_date(): void
    {
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');
        $employee = $user->employee;

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/previous-positions', [
            'employee_uuid'   => $employee->uuid,
            'department_uuid' => $employee->department->uuid,
            'position_uuid'   => Position::create(['name' => 'Lecturer'])->uuid,
        ])->assertJsonValidationErrors('start');
    }

    public function test_an_affiliation_keeps_the_years_entered(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/affiliations', [
            'employee_uuid' => $user->employee->uuid,
            'association'   => 'Ghana Institution of Engineers',
            'description'   => 'Member',
            'role'          => 'Member',
            'start'         => '2012-01-01',
            'end'           => '2018',
        ])->assertOk();

        $info = InformationUpdate::where('information_type', 'Affiliation')->sole()->new_info;
        $this->assertSame('2012', $info['start']);
        $this->assertSame('2018', $info['end']);
    }
}
