<?php

namespace Tests\Feature;

use App\Events\ApprovalRequested;
use App\Exceptions\UserFacingException;
use App\Models\InformationUpdate;
use App\Services\UpdateApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PendingUpdateAmendTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_requester_can_correct_their_pending_update(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');
        $employee = $user->employee;
        $original = $employee->first_name;
        $service = app(UpdateApprovalService::class);

        $first = $service->update($employee, ['first_name' => 'Jhon', 'ssnit_number' => 'S123'], $user->id);
        $second = $service->update($employee->fresh(), ['first_name' => 'John', 'ssnit_number' => 'S123'], $user->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(['first_name' => 'John', 'ssnit_number' => 'S123'], $second->fresh()->new_info);
        $this->assertSame(1, InformationUpdate::where('status', 'pending')->count());
        $this->assertSame($original, $employee->fresh()->first_name);
    }

    public function test_reverting_all_pending_changes_cancels_the_request(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');
        $employee = $user->employee;
        $service = app(UpdateApprovalService::class);

        $first = $service->update($employee, ['first_name' => 'Jhon'], $user->id);
        $result = $service->update($employee->fresh(), ['first_name' => $employee->first_name], $user->id);

        $this->assertNull($result);
        $this->assertSame('canceled', $first->fresh()->status->value);
    }

    public function test_another_user_cannot_amend_someone_elses_pending_update(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess([]);
        $user = $this->userWithRole('staff');
        $other = $this->userWithRole('staff');
        $service = app(UpdateApprovalService::class);

        $service->update($user->employee, ['first_name' => 'Jhon'], $user->id);

        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('already pending approval');
        $service->update($user->employee->fresh(), ['first_name' => 'John'], $other->id);
    }
}
