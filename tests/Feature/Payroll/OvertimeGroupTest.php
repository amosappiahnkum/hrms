<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\OvertimeRequestGroup;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\Payroll\WorkOrder;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Overtime on several dates at once (e.g. every Saturday on one PO): a request per date, approved together. */
class OvertimeGroupTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $staff;
    private WorkOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00'); // a Thursday
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.overtime'])->update(['value' => true]);
        $settings = app(SettingService::class);
        $settings->refreshCache();
        $settings->set('payroll.overtime_mode', 'before');
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'view-payroll', 'view-overtime']);
        $this->staff = $this->userWithRole('staff');
        EmployeePayProfile::create(['employee_id' => $this->staff->employee_id, 'effective_from' => '2026-01-01', 'basic_salary' => 4400, 'payment_method' => 'cash']);
        $this->po = WorkOrder::create(['number' => 'PO# 4500028551', 'description' => 'NDT inspection']);

        Sanctum::actingAs($this->hr);
        foreach (['weekday' => ['OT_WD', 1.25], 'weekend' => ['OT_WE', 1.5]] as $kind => [$code, $rate]) {
            $c = PayComponent::create(['code' => $code, 'name' => ucfirst($kind) . ' overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => $rate, 'taxable' => true]);
            $this->postJson('/api/v1/payroll/overtime-types', ['name' => ucfirst($kind), 'pay_component_uuid' => $c->uuid, 'applies_on' => $kind])->assertCreated();
        }
        $this->postJson('/api/v1/payroll/approval-workflows', [
            'process' => 'overtime', 'name' => 'Overtime', 'is_default' => true,
            'steps' => [['name' => 'Supervisor', 'approver_type' => 'permission', 'approver_value' => 'configure-payroll', 'can_adjust' => ['approved_hours']]],
        ])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ask(array $dates, array $extra = [])
    {
        Sanctum::actingAs($this->staff);

        return $this->postJson('/api/v1/payroll/my-overtime', $extra + [
            'dates' => $dates, 'work_order_uuid' => $this->po->uuid, 'reason' => 'Vessel turnaround',
        ]);
    }

    public function test_several_dates_are_one_approval_with_a_request_per_date(): void
    {
        // Two Saturdays and a Monday.
        $this->ask([['date' => '2026-10-03', 'hours' => 6], ['date' => '2026-10-05', 'hours' => 3], ['date' => '2026-10-10', 'hours' => 6]])
            ->assertCreated()->assertJsonPath('message', '3 days of overtime sent for approval.')
            ->assertJsonPath('data.group.days', 3)->assertJsonPath('data.group.from', '2026-10-03')->assertJsonPath('data.group.to', '2026-10-10');

        $group = OvertimeRequestGroup::sole();
        $this->assertSame(['Weekend', 'Weekday', 'Weekend'], $group->requests()->with('type')->get()->pluck('type.name')->all());
        $this->assertSame(['PO# 4500028551'], $group->requests()->with('workOrder')->get()->pluck('workOrder.number')->unique()->values()->all());

        // The approver sees one request, with each date's hours to adjust.
        Sanctum::actingAs($this->hr);
        $inbox = $this->getJson('/api/v1/payroll/approvals')->assertJsonPath('data.meta.waiting_count', 1)->json('data.data.0');
        $this->assertStringStartsWith('3 days of overtime, 3 Oct – 10 Oct 2026 (15 h)', $inbox['summary']);
        $dates = $inbox['adjustable'][0]['value'];
        $this->assertCount(3, $dates);

        // Lower one date, leave one out; the rest as asked.
        [$sat1, $mon, $sat2] = array_keys($dates);
        $this->postJson("/api/v1/payroll/approvals/{$inbox['uuid']}/decide", ['decision' => 'approved', 'adjustments' => ['approved_hours' => [$sat1 => 7]]])
            ->assertStatus(422); // more than asked
        $this->postJson("/api/v1/payroll/approvals/{$inbox['uuid']}/decide", ['decision' => 'approved', 'adjustments' => ['approved_hours' => [$sat1 => 4, $mon => 0]]])->assertOk();

        $byDate = OvertimeRequest::orderBy('work_date')->get()->map(fn ($r) => [$r->work_date->toDateString(), $r->status, (float) $r->approved_hours])->all();
        $this->assertSame([['2026-10-03', 'approved', 4.0], ['2026-10-05', 'rejected', 0.0], ['2026-10-10', 'approved', 6.0]], $byDate);
        $this->assertSame('approved', $group->fresh()->status);
    }

    public function test_every_date_is_checked_and_the_month_as_a_whole(): void
    {
        $this->ask([['date' => '2026-10-03', 'hours' => 6]])->assertUnprocessable(); // one date: a single request
        $this->ask([['date' => '2026-10-03', 'hours' => 6], ['date' => '2026-10-03', 'hours' => 2]])->assertStatus(422)
            ->assertJsonPath('message', 'Each date can be in the request once.');
        $this->ask([['date' => '2026-10-03', 'hours' => 6], ['date' => '2026-10-04', 'hours' => 13]])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Sun 4 Oct: No more than 12 hours'));
        // A rule broken on every date is said once.
        $this->ask([['date' => '2026-10-03', 'hours' => 6], ['date' => '2026-10-04', 'hours' => 6]], ['reason' => ''])->assertStatus(422)
            ->assertJsonPath('message', 'Give the reason it is urgent.');

        app(SettingService::class)->set('payroll.overtime_max_hours_per_month', 20);
        $this->ask([['date' => '2026-10-03', 'hours' => 8], ['date' => '2026-10-10', 'hours' => 8], ['date' => '2026-10-17', 'hours' => 8]])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'No more than 20 hours of overtime a month'));
        $this->assertSame(0, OvertimeRequest::count());
    }

    public function test_the_dates_are_withdrawn_or_rejected_together(): void
    {
        $this->ask([['date' => '2026-10-03', 'hours' => 6], ['date' => '2026-10-10', 'hours' => 6]])->assertCreated();
        $first = OvertimeRequest::orderBy('work_date')->first();
        $this->postJson("/api/v1/payroll/my-overtime/{$first->uuid}/cancel")->assertOk();
        $this->assertSame(['cancelled', 'cancelled'], OvertimeRequest::pluck('status')->all());
        $this->assertSame('cancelled', OvertimeRequestGroup::sole()->approval->status);

        $this->ask([['date' => '2026-10-17', 'hours' => 6], ['date' => '2026-10-24', 'hours' => 6]])->assertCreated();
        Sanctum::actingAs($this->hr);
        $approval = $this->getJson('/api/v1/payroll/approvals')->json('data.data.0.uuid');
        $this->postJson("/api/v1/payroll/approvals/{$approval}/decide", ['decision' => 'rejected', 'comment' => 'Not this month'])->assertOk();
        $this->assertSame(['rejected', 'rejected'], OvertimeRequest::whereIn('work_date', ['2026-10-17', '2026-10-24'])->pluck('status')->all());
    }

    public function test_hr_records_several_dates_for_someone(): void
    {
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/overtime', [
            'employee_uuid' => $this->staff->employee->uuid, 'dates' => [['date' => '2026-10-03', 'hours' => 4], ['date' => '2026-10-04', 'hours' => 4]],
            'activity_description' => 'Blasting', 'reason' => 'Shutdown',
        ])->assertCreated()->assertJsonPath('data.group.days', 2);
    }
}
