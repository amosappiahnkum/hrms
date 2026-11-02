<?php

namespace Tests\Feature;

use App\Events\ApprovalRequested;
use App\Models\Config\Department;
use App\Models\Config\EducationLevel;
use App\Models\Position;
use App\Models\SelfService\Education;
use App\Models\Training\PreviousPosition;
use App\Services\UpdateApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PendingEditFormValuesTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_a_previous_position_gives_its_pending_change_by_uuid(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');
        $employee = $user->employee;
        Sanctum::actingAs($user);
        $position = PreviousPosition::create([
            'employee_id' => $employee->id,
            'department_id' => $employee->department_id,
            'position_id' => Position::create(['name' => 'Lecturer'])->id,
            'start' => '2015-03-01',
        ]);
        $newDepartment = Department::create(['name' => 'Civil Engineering']);
        $newPosition = Position::create(['name' => 'Senior Lecturer']);

        app(UpdateApprovalService::class)->update($position, [
            'department_id' => $newDepartment->id,
            'position_id' => $newPosition->id,
        ], $user->id);

        $values = $this->getJson("/api/v1/previous-positions/{$position->uuid}")
            ->assertOk()->json('data.pending_values');

        $this->assertSame($newDepartment->uuid, $values['department_uuid']);
        $this->assertSame($newPosition->uuid, $values['position_uuid']);
    }

    public function test_a_qualification_gives_its_pending_change_by_uuid(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');
        $qualification = Education::create([
            'employee_id' => $user->employee->id,
            'education_level_id' => EducationLevel::create(['name' => 'Bachelor'])->id,
            'institution' => 'KNUST',
            'qualification' => 'BSc Computer Science',
            'date' => '2014-07-01',
        ]);
        $masters = EducationLevel::create(['name' => 'Masters']);

        app(UpdateApprovalService::class)->update($qualification, [
            'education_level_id' => $masters->id,
            'institution' => 'UG',
        ], $user->id);

        Sanctum::actingAs($user);
        $values = $this->getJson("/api/v1/qualifications/{$qualification->uuid}")
            ->assertOk()->json('data.pending_values');

        $this->assertSame($masters->uuid, $values['education_level_uuid']);
        $this->assertSame('UG', $values['institution']);
    }
}
