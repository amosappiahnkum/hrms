<?php

namespace Tests\Feature;

use App\Events\ApprovalRequested;
use App\Models\InformationUpdate;
use App\Models\JobCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class JobDetailApprovalTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private function payload($employee, array $overrides = []): array
    {
        $job = $employee->jobDetail->fresh();

        return array_merge([
            'employee_uuid'     => $employee->uuid,
            'job_category_uuid' => $job->jobCategory->uuid,
            'joined_date'       => $job->joined_date?->toDateString(),
            'room'              => $job->room,
            'location'          => $job->location ?? 'Main Campus',
        ], $overrides);
    }

    public function test_staff_job_detail_changes_wait_for_approval(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');
        $employee = $user->employee;
        $employee->jobDetail()->update([
            'joined_date' => '2018-09-01', 'room' => 'A1', 'location' => 'Main Campus',
            'job_category_id' => JobCategory::create(['name' => 'Senior Staff', 'leave_entitlement' => 30])->id,
        ]);

        Sanctum::actingAs($user);
        $this->putJson("/api/v1/employees/{$employee->uuid}/job-detail", $this->payload($employee, ['room' => 'B12']))
            ->assertOk();

        $this->assertSame('A1', $employee->jobDetail->fresh()->room);
        $update = InformationUpdate::where('information_type', 'JobDetail')->sole();
        $this->assertSame(['room' => 'B12'], $update->new_info);
    }

    public function test_saving_unchanged_job_detail_requests_nothing(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');
        $employee = $user->employee;
        $employee->jobDetail()->update([
            'joined_date' => '2018-09-01', 'room' => 'A1', 'location' => 'Main Campus',
            'job_category_id' => JobCategory::create(['name' => 'Senior Staff', 'leave_entitlement' => 30])->id,
        ]);

        Sanctum::actingAs($user);
        $this->putJson("/api/v1/employees/{$employee->uuid}/job-detail", $this->payload($employee))
            ->assertOk();

        $this->assertSame(0, InformationUpdate::where('information_type', 'JobDetail')->count());
    }

    public function test_the_job_detail_shows_the_pending_change(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');
        $employee = $user->employee;
        $employee->jobDetail()->update([
            'joined_date' => '2018-09-01', 'room' => 'A1', 'location' => 'Main Campus',
            'job_category_id' => JobCategory::create(['name' => 'Senior Staff', 'leave_entitlement' => 30])->id,
        ]);
        $newCategory = JobCategory::create(['name' => 'Senior Member', 'leave_entitlement' => 36]);

        Sanctum::actingAs($user);
        $this->putJson("/api/v1/employees/{$employee->uuid}/job-detail", $this->payload($employee, [
            'job_category_uuid' => $newCategory->uuid,
        ]))->assertOk();

        $data = $this->getJson("/api/v1/employees/{$employee->uuid}/job-detail")->assertOk()->json('data');

        $this->assertSame('Senior Staff', $data['job_category']);
        $this->assertSame($newCategory->uuid, $data['pending_values']['job_category_uuid']);
        $this->assertSame(
            [['field' => 'job_category_id', 'old' => 'Senior Staff', 'new' => 'Senior Member']],
            $data['pending_changes']
        );
    }
}
