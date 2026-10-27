<?php

namespace Tests\Feature;

use App\Events\ApprovalRequested;
use App\Services\UpdateApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class BiographyApprovalTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_a_staff_summary_waits_for_approval_and_shows_as_pending(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess(['employees.biography']);
        $user = $this->userWithRole('staff');
        $employee = $user->employee;
        $employee->update(['bio' => 'Lecturer in Computer Science.']);

        Sanctum::actingAs($user);
        $this->putJson("/api/v1/employees/{$employee->uuid}/biography", ['biography' => 'Senior Lecturer in Computer Science.'])
            ->assertOk();

        $this->assertSame('Lecturer in Computer Science.', $employee->fresh()->bio);
        $this->getJson("/api/v1/employees/{$employee->uuid}/biography")
            ->assertOk()
            ->assertJsonPath('data.bio', 'Lecturer in Computer Science.')
            ->assertJsonPath('data.pending_bio', 'Senior Lecturer in Computer Science.');
    }

    public function test_a_summary_joins_a_pending_personal_info_request(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess(['employees.biography']);
        $user = $this->userWithRole('staff');
        $employee = $user->employee;
        $pending = app(UpdateApprovalService::class)->update($employee, ['marital_status' => 'married'], $user->id);

        Sanctum::actingAs($user);
        $this->putJson("/api/v1/employees/{$employee->uuid}/biography", ['biography' => 'Lecturer.'])
            ->assertOk();

        $this->assertSame(['marital_status' => 'married', 'bio' => 'Lecturer.'], $pending->fresh()->new_info);
    }

    public function test_hr_updates_the_summary_directly(): void
    {
        $this->setUpAccess(['employees.biography']);
        $hr = $this->userWithRole('hr');
        $employee = $this->userWithRole('staff')->employee;

        Sanctum::actingAs($hr);
        $this->putJson("/api/v1/employees/{$employee->uuid}/biography", ['biography' => 'Registrar.'])
            ->assertOk();

        $this->assertSame('Registrar.', $employee->fresh()->bio);
        $this->getJson("/api/v1/employees/{$employee->uuid}/biography")->assertJsonPath('data.pending_bio', null);
    }
}
