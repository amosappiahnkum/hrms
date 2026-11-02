<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\Approval;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\OvertimeType;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** The only HR approver asks for something: it goes to someone else, or waits flagged until someone can decide. */
class ApprovalEscalationTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.overtime')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo('configure-payroll');
        EmployeePayProfile::create(['employee_id' => $this->hr->employee->id, 'effective_from' => '2026-01-01', 'basic_salary' => 5000, 'payment_method' => 'cash']);
        $ot = PayComponent::create(['code' => 'OT', 'name' => 'Overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 1.5, 'taxable' => true]);
        OvertimeType::create(['name' => 'Any day', 'pay_component_id' => $ot->id, 'applies_on' => 'any']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hrAsks(): Approval
    {
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/my-overtime', ['work_date' => '2026-06-29', 'hours' => 3, 'reason' => 'Audit'])->assertCreated();

        return Approval::sole();
    }

    public function test_it_goes_to_a_payroll_approver_when_the_only_configurer_asks(): void
    {
        $finance = $this->userWithRole('staff');
        $finance->givePermissionTo('approve-payroll');

        $approval = $this->hrAsks();
        $this->assertSame([$finance->id], $approval->current_approver_ids);

        Sanctum::actingAs($finance);
        $this->postJson("/api/v1/payroll/approvals/{$approval->uuid}/decide", ['decision' => 'approved'])->assertOk();
    }

    public function test_with_nobody_to_ask_it_is_flagged_and_whoever_is_given_the_right_can_decide(): void
    {
        $approval = $this->hrAsks();
        $this->assertSame([], $approval->current_approver_ids);
        $this->getJson('/api/v1/payroll/my-overtime')->assertJsonPath('data.data.0.approval.no_approver', true);
        // The requester still can't decide their own.
        $this->getJson('/api/v1/payroll/approvals')->assertJsonPath('data.meta.waiting_count', 0);
        $this->postJson("/api/v1/payroll/approvals/{$approval->uuid}/decide", ['decision' => 'approved'])->assertForbidden();

        // Someone is given the permission later: it's in their inbox and they can decide it.
        $finance = $this->userWithRole('staff');
        $finance->givePermissionTo('approve-payroll');
        Sanctum::actingAs($finance);
        $this->getJson('/api/v1/payroll/approvals')->assertJsonPath('data.meta.waiting_count', 1)->assertJsonPath('data.data.0.can_decide', true);
        $this->postJson("/api/v1/payroll/approvals/{$approval->uuid}/decide", ['decision' => 'approved'])->assertOk();
        $this->assertSame('approved', $approval->fresh()->status);
    }
}
