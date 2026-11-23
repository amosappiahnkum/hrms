<?php

namespace Tests\Feature;

use App\Models\Config\LeaveType;
use App\Models\Config\LeaveTypeLevelConfig;
use App\Models\JobCategory;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveRequest;
use App\Models\SelfService\Employee;
use App\Services\Leave\LeaveBalance;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class LeaveBalanceTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private Employee $employee;
    private LeaveTypeLevelConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess(['leave.enabled', 'leave.self_service']);
        Carbon::setTestNow('2026-06-15 09:00:00');

        $user = $this->userWithRole('staff');
        $category = JobCategory::create(['name' => 'Senior Staff', 'leave_entitlement' => 30]);
        $user->employee->jobDetail()->update(['job_category_id' => $category->id, 'joined_date' => '2020-01-01']);
        $this->employee = $user->employee->fresh();

        $typeId = DB::table('leave_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Annual', 'entitlement_type' => 'fixed', 'number_of_days' => 0,
            'start_of_annual_cycle' => '2026-01-01', 'request_type' => 'days',
            'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->config = LeaveTypeLevelConfig::create([
            'leave_type_id' => $typeId, 'job_category_id' => $category->id, 'number_of_days' => 20,
            'allow_half_day' => false, 'allow_carry_forward' => false,
            'maximum_consecutive_days' => 10, 'should_request_before' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function leave(string $start, float $days, string $status = 'hr_approved'): void
    {
        LeaveRequest::create([
            'employee_id' => $this->employee->id, 'supervisor_id' => $this->employee->id,
            'department_id' => $this->employee->department_id, 'leave_type_id' => $this->config->leave_type_id,
            'days_requested' => $days, 'days_approved' => $status === 'hr_approved' ? $days : 0,
            'start_date' => $start, 'end_date' => $start, 'status' => $status, 'reason' => 'Test',
            'user_id' => $this->employee->userAccount->id,
        ]);
    }

    private function balance(?string $on = null): array
    {
        return app(LeaveBalance::class)->for($this->employee, $this->config->fresh(), $on ? Carbon::parse($on) : null);
    }

    private function violation(string $start, float $days): ?string
    {
        return app(LeaveBalance::class)->violation($this->employee, $this->config->fresh(), Carbon::parse($start), $days);
    }

    public function test_the_balance_resets_each_leave_year(): void
    {
        $this->leave('2025-03-10', 15);
        $this->leave('2026-02-10', 5);
        $this->leave('2026-07-01', 2, 'pending');

        $now = $this->balance();
        $this->assertSame('2026-01-01', $now['cycle_start']);
        $this->assertEquals(20, $now['total']);
        $this->assertEquals(5, $now['used']);
        $this->assertEquals(13, $now['available']);
        $this->assertEquals(15, $this->balance('2025-06-01')['used']);
    }

    public function test_the_leave_year_follows_the_cycle_start_setting(): void
    {
        app(SettingService::class)->set('leave.cycle_start', '07-01');
        $this->leave('2026-02-10', 5);

        $balance = $this->balance();
        $this->assertSame('2025-07-01', $balance['cycle_start']);
        $this->assertSame('2026-06-30', $balance['cycle_end']);
        $this->assertEquals(5, $balance['used']);
        $this->assertEquals(0, $this->balance('2026-07-01')['used']);
    }

    public function test_unused_days_carry_forward_up_to_the_cap(): void
    {
        $this->leave('2025-03-10', 12); // 8 left of 2025
        $this->assertEquals(0, $this->balance()['carried_forward']);

        $this->config->update(['allow_carry_forward' => true]);
        $this->assertEquals(8, $this->balance()['carried_forward']);
        $this->assertEquals(28, $this->balance()['total']);

        $this->config->update(['max_carry_forward_days' => 5]);
        $this->assertEquals(5, $this->balance()['carried_forward']);
        $this->assertEquals(25, $this->balance()['total']);
    }

    public function test_adjustments_count_in_the_year_they_are_made(): void
    {
        $adjustment = LeaveBalanceAdjustment::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->config->leave_type_id,
            'days' => 4, 'reason' => 'Overtime', 'adjusted_by' => $this->employee->userAccount->id,
        ]);
        $this->assertEquals(24, $this->balance()['total']);

        $adjustment->forceFill(['created_at' => '2025-05-01'])->save();
        $this->assertEquals(20, $this->balance()['total']);
    }

    public function test_requests_must_meet_the_category_rules(): void
    {
        $this->assertNull($this->violation('2026-06-22', 5));

        // Notice: at least 3 days ahead.
        $this->assertStringContainsString('at least 3 day', $this->violation('2026-06-17', 2));
        // Longest request.
        $this->assertStringContainsString('at most 10 days', $this->violation('2026-06-22', 11));
        // Half days only when allowed.
        $this->assertStringContainsString('whole day', $this->violation('2026-06-22', 1.5));
        $this->config->update(['allow_half_day' => true]);
        $this->assertNull($this->violation('2026-06-22', 1.5));
        $this->assertNotNull($this->violation('2026-06-22', 1.25));

        // Balance of the year the leave starts in.
        $this->leave('2026-03-02', 10);
        $this->leave('2026-04-06', 8);
        $this->assertStringContainsString('Insufficient', $this->violation('2026-06-22', 3));
        $this->assertNull($this->violation('2027-01-11', 3));
    }

    public function test_a_half_day_request_is_stored_with_its_half(): void
    {
        $this->config->update(['allow_half_day' => true, 'should_request_before' => 0]);
        $this->leave('2026-06-22', 2.5, 'pending');

        $this->assertEquals(2.5, LeaveRequest::sole()->days_requested);
        $this->assertEquals(2.5, $this->balance()['pending']);
    }
}
