<?php

namespace Tests\Feature;

use App\Events\ApprovalRequested;
use App\Models\InformationUpdate;
use App\Models\SelfService\EmergencyContact;
use App\Services\UpdateApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PendingCreateAmendTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private function pendingContact($user): InformationUpdate
    {
        return app(UpdateApprovalService::class)->create(new EmergencyContact(), [
            'name' => 'Jhon Doe',
            'relationship' => 'Brother',
            'phone_number' => '0240000000',
            'employee_id' => $user->employee->id,
        ], $user->id);
    }

    public function test_requester_can_correct_a_pending_new_record(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess(['information_updates.enabled']);
        $user = $this->userWithRole('staff');
        $pending = $this->pendingContact($user);

        Sanctum::actingAs($user);
        $this->putJson("/api/v1/approvals/{$pending->uuid}", [
            'name' => 'John Doe',
            'relationship' => 'Brother',
            'phone_number' => '0240000000',
            'employee_uuid' => $user->employee->uuid,
        ])->assertOk();

        $info = $pending->fresh()->new_info;
        $this->assertSame('John Doe', $info['name']);
        $this->assertSame($user->employee->id, $info['employee_id']);
        $this->assertSame('pending', $pending->fresh()->status->value);
        $this->assertSame(0, EmergencyContact::count());
    }

    public function test_the_request_gives_form_values_with_related_uuids(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess(['information_updates.enabled']);
        $user = $this->userWithRole('staff');
        $pending = $this->pendingContact($user);

        Sanctum::actingAs($user);
        $values = $this->getJson("/api/v1/approvals/{$pending->uuid}")
            ->assertOk()
            ->json('data.form_values');

        $this->assertSame('Jhon Doe', $values['name']);
        $this->assertSame($user->employee->uuid, $values['employee_uuid']);
    }

    public function test_the_record_stays_with_the_original_employee(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess(['information_updates.enabled']);
        $user = $this->userWithRole('staff');
        $other = $this->userWithRole('staff');
        $pending = $this->pendingContact($user);

        Sanctum::actingAs($user);
        $this->putJson("/api/v1/approvals/{$pending->uuid}", [
            'name' => 'John Doe',
            'relationship' => 'Brother',
            'employee_uuid' => $other->employee->uuid,
        ])->assertOk();

        $this->assertSame($user->employee->id, $pending->fresh()->new_info['employee_id']);
    }

    public function test_it_uses_the_records_validation_rules(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess(['information_updates.enabled']);
        $user = $this->userWithRole('staff');
        $pending = $this->pendingContact($user);

        Sanctum::actingAs($user);
        $this->putJson("/api/v1/approvals/{$pending->uuid}", ['name' => 'John Doe'])
            ->assertStatus(422);

        $this->assertSame('Jhon Doe', $pending->fresh()->new_info['name']);
    }

    public function test_another_user_cannot_edit_the_request(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess(['information_updates.enabled']);
        $user = $this->userWithRole('staff');
        $other = $this->userWithRole('staff');
        $pending = $this->pendingContact($user);

        Sanctum::actingAs($other);
        $this->putJson("/api/v1/approvals/{$pending->uuid}", [
            'name' => 'Hacked',
            'relationship' => 'Brother',
        ])->assertForbidden();

        $this->assertSame('Jhon Doe', $pending->fresh()->new_info['name']);
    }

    public function test_reviewed_requests_cannot_be_edited(): void
    {
        Event::fake([ApprovalRequested::class]);
        $this->setUpAccess(['information_updates.enabled']);
        $user = $this->userWithRole('staff');
        $pending = $this->pendingContact($user);
        $pending->update(['status' => 'rejected']);

        Sanctum::actingAs($user);
        $this->putJson("/api/v1/approvals/{$pending->uuid}", [
            'name' => 'John Doe',
            'relationship' => 'Brother',
        ])->assertStatus(409);
    }
}
