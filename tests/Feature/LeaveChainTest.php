<?php

namespace Tests\Feature;

use App\Models\Config\Department;
use App\Models\LeaveRequest;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/**
 * Leave follows the reporting line: the supervisor approves first, then heads above as the setting says, then HR.
 * Technical Directorate › Engineering › Inspection unit.
 */
class LeaveChainTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $director;
    private User $engHead;
    private User $unitHead;
    private User $officer;
    private string $leaveType;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00'); // a Wednesday
        $this->setUpAccess(['leave.enabled', 'leave.self_service', 'leave.team_visibility', 'leave.hr_approval', 'leave.types_management']);
        Notification::fake();
        Mail::fake();

        $this->director = $this->userWithRole('hod');
        $this->engHead = $this->userWithRole('hod');
        $this->unitHead = $this->userWithRole('hod');
        $directorate = Department::create(['name' => 'Technical Directorate', 'level' => 0, 'hod' => $this->director->employee_id]);
        $engineering = Department::create(['name' => 'Engineering', 'level' => 1, 'hod' => $this->engHead->employee_id, 'parent_department_id' => $directorate->id]);
        $inspection = Department::create(['name' => 'Inspection', 'level' => 2, 'hod' => $this->unitHead->employee_id, 'parent_department_id' => $engineering->id]);
        $this->director->employee->update(['department_id' => $directorate->id]);
        $this->engHead->employee->update(['department_id' => $engineering->id]);
        $this->unitHead->employee->update(['department_id' => $inspection->id]);
        $this->officer = $this->userWithRole('staff');
        $this->officer->employee->update(['department_id' => $inspection->id]);
        app(SettingService::class)->set('org.department_levels', ['Directorate', 'Department', 'Unit']);

        $this->leaveType = (string) Str::uuid();
        DB::table('leave_types')->insert([
            'uuid' => $this->leaveType, 'name' => 'Annual', 'entitlement_type' => 'fixed', 'number_of_days' => 20,
            'start_of_annual_cycle' => now()->startOfYear()->toDateString(), 'request_type' => 'days',
            'user_id' => $this->director->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The officer's entitlement comes from their job category.
        $category = DB::table('job_categories')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'Senior staff', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leave_type_level_configs')->insert([
            'uuid' => (string) Str::uuid(), 'leave_type_id' => DB::table('leave_types')->where('uuid', $this->leaveType)->value('id'),
            'job_category_id' => $category, 'number_of_days' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->officer->employee->jobDetail()->update(['job_category_id' => $category]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ask(): LeaveRequest
    {
        Sanctum::actingAs($this->officer);
        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->leaveType, 'number_of_days' => 2, 'start_date' => '2026-10-19', 'reason' => 'Family event',
        ])->assertOk();

        return LeaveRequest::latest('id')->firstOrFail();
    }

    private function decide(User $head, LeaveRequest $leave, string $decision = 'approved')
    {
        Sanctum::actingAs($head);

        return $this->postJson('/api/v1/change-leave-status', [
            'id' => $leave->uuid, 'decision' => $decision, 'status' => $decision === 'approved' ? 'hod_approved' : 'hod_rejected',
            'days_requested' => 2, 'start_date' => '2026-10-19', 'sup_reason' => 'ok',
        ]);
    }

    public function test_by_default_the_supervisor_approves_then_hr(): void
    {
        $leave = $this->ask();
        $this->assertSame([$this->unitHead->employee_id, [$this->unitHead->employee_id]], [$leave->supervisor_id, $leave->approval_chain]);

        $this->decide($this->unitHead, $leave)->assertOk();
        $this->assertSame('hod_approved', $leave->fresh()->status->value);
    }

    public function test_heads_above_approve_in_turn_before_hr(): void
    {
        app(SettingService::class)->set('leave.approval_chain', ['stop' => 'level', 'level' => 1]); // up to the department
        $leave = $this->ask();
        $this->assertSame([$this->unitHead->employee_id, $this->engHead->employee_id], $leave->approval_chain);

        // Not the next head's turn yet.
        $this->decide($this->engHead, $leave)->assertForbidden();
        $this->decide($this->unitHead, $leave)->assertOk();
        $leave->refresh();
        $this->assertSame(['pending', $this->engHead->employee_id, 1], [$leave->status->value, $leave->supervisor_id, $leave->approval_step]);
        $this->decide($this->unitHead, $leave)->assertForbidden(); // decided already

        Sanctum::actingAs($this->engHead);
        $this->assertSame($leave->uuid, $this->getJson('/api/v1/team-request')->json('data.0.uuid'));
        $this->getJson("/api/v1/leave-requests/{$leave->uuid}")->assertOk()
            ->assertJsonPath('approval_chain.0.state', 'approved')->assertJsonPath('approval_chain.1.state', 'current');

        $this->decide($this->engHead, $leave)->assertOk();
        $this->assertSame('hod_approved', $leave->fresh()->status->value);
        $this->assertSame([['hod', 0], ['hod', 1]], $leave->approvals()->orderBy('id')->get()->map(fn ($a) => [$a->role, $a->step])->all());
    }

    public function test_without_an_hr_step_the_last_head_approves_it(): void
    {
        \App\Models\Config\Setting::where('key', 'features.leave.hr_approval')->update(['value' => false]);
        app(SettingService::class)->refreshCache();
        app(SettingService::class)->set('leave.approval_chain', ['stop' => 'level', 'level' => 1]);

        // A head with another to go only passes it on; the last one's approval is final.
        $leave = $this->ask();
        $this->decide($this->unitHead, $leave)->assertOk();
        $this->assertSame('pending', $leave->fresh()->status->value);
        $this->decide($this->engHead, $leave->fresh())->assertOk();
        $this->assertSame(['hr_approved', 2], [$leave->fresh()->status->value, (int) $leave->fresh()->days_approved]);

        // A rejection is still a rejection.
        $leave->update(['status' => 'canceled']);
        $other = $this->ask();
        $this->decide($this->unitHead, $other, 'rejected')->assertOk();
        $this->assertSame('hod_rejected', $other->fresh()->status->value);

        // The top head (no one above them) has their own request approved as asked.
        $this->director->employee->jobDetail()->update(['job_category_id' => $this->officer->employee->jobDetail->job_category_id]);
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->leaveType, 'number_of_days' => 1, 'start_date' => '2026-10-20', 'reason' => 'Rest',
        ])->assertOk();
        $this->assertSame('hr_approved', LeaveRequest::where('employee_id', $this->director->employee_id)->sole()->status->value);
    }

    public function test_a_rejection_stops_the_chain_and_reports_to_comes_first(): void
    {
        app(SettingService::class)->set('leave.approval_chain', ['stop' => 'top']);
        $lead = $this->userWithRole('staff');
        $this->officer->employee->update(['reports_to_id' => $lead->employee_id]);

        $leave = $this->ask();
        $this->assertSame([$lead->employee_id, $this->unitHead->employee_id, $this->engHead->employee_id, $this->director->employee_id], $leave->approval_chain);

        $this->decide($lead, $leave)->assertOk();
        $this->decide($this->unitHead, $leave->fresh(), 'rejected')->assertOk();
        $this->assertSame(['hod_rejected', 1], [$leave->fresh()->status->value, $leave->fresh()->approval_step]);
    }

    public function test_hr_sets_how_far_leave_goes(): void
    {
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo('edit-leave-types');
        Sanctum::actingAs($hr);
        $this->getJson('/api/v1/leave-settings')->assertOk()->assertJsonPath('data.approval_chain.stop', 'count')->assertJsonPath('data.approval_chain.count', 0);
        $this->putJson('/api/v1/leave-settings', ['approval_chain' => ['stop' => 'level', 'level' => 9]])->assertJsonValidationErrors('approval_chain.level');
        $this->putJson('/api/v1/leave-settings', ['approval_chain' => ['stop' => 'count', 'count' => 2]])->assertOk()
            ->assertJsonPath('data.approval_chain.count', 2);

        Sanctum::actingAs($this->officer);
        $this->putJson('/api/v1/leave-settings', ['approval_chain' => ['stop' => 'top']])->assertForbidden();
    }
}
