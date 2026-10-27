<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Holiday;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\Payroll\Timesheet;
use App\Models\Payroll\TimesheetEntry;
use App\Models\Payroll\WorkSite;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Timesheet entries (Phase 12): hours per site, split into normal and overtime, and overtime approved in advance. */
class TimesheetEntriesTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $staff;
    private WorkSite $base;
    private WorkSite $offshore;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 09:00:00'); // a Tuesday; September's sheet is 21 Aug – 20 Sep
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.timesheets', 'features.payroll.overtime'])->update(['value' => true]);
        $settings = app(SettingService::class);
        $settings->refreshCache();
        $settings->set('payroll.period_start_day', 21);
        $settings->set('payroll.time_source', 'timesheet');
        $settings->set('payroll.overtime_mode', 'before');
        $settings->set('payroll.overtime_advance_for', ['weekend', 'holiday']);
        $settings->set('payroll.timesheet_columns', [
            ['key' => 'normal_hours', 'label' => 'Normal 8–5', 'type' => 'hours', 'normal' => true],
            ['key' => 'ot_weekday', 'label' => 'Overtime (Mon–Fri)', 'type' => 'hours', 'feeds' => 'overtime', 'day_kind' => 'weekday'],
            ['key' => 'ot_weekend', 'label' => 'Weekend overtime', 'type' => 'hours', 'feeds' => 'overtime', 'day_kind' => 'weekend'],
            ['key' => 'ot_holiday', 'label' => 'Holiday overtime', 'type' => 'hours', 'feeds' => 'overtime', 'day_kind' => 'holiday'],
            ['key' => 'offshore', 'label' => 'Offshore days', 'type' => 'check', 'requires_offshore' => true],
        ]);
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'view-payroll', 'manage-timesheets', 'view-overtime']);
        $this->staff = $this->userWithRole('staff');
        $this->staff->employee->update(['ssnit_number' => 'C1']);
        EmployeePayProfile::create(['employee_id' => $this->staff->employee_id, 'effective_from' => '2026-01-01', 'basic_salary' => 4400, 'payment_method' => 'cash']);
        $this->base = WorkSite::create(['code' => 'TC', 'name' => 'Takoradi base']);
        $this->offshore = WorkSite::create(['code' => 'MV25', 'name' => 'FPSO MV25', 'is_offshore' => true]);

        Sanctum::actingAs($this->hr);
        foreach (['weekday' => ['OT_WD', 1.25], 'weekend' => ['OT_WE', 1.5], 'holiday' => ['OT_PH', 2]] as $kind => [$code, $rate]) {
            $component = PayComponent::create(['code' => $code, 'name' => "{$kind} overtime", 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => $rate, 'taxable' => true]);
            $this->postJson('/api/v1/payroll/overtime-types', ['name' => ucfirst($kind), 'pay_component_uuid' => $component->uuid, 'applies_on' => $kind])->assertCreated();
        }
        foreach (['overtime', 'timesheet'] as $process) {
            $this->postJson('/api/v1/payroll/approval-workflows', [
                'process' => $process, 'name' => ucfirst($process), 'is_default' => true,
                'steps' => [['name' => 'Supervisor', 'approver_type' => 'permission', 'approver_value' => 'configure-payroll']],
            ])->assertCreated();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sheet(): array
    {
        Sanctum::actingAs($this->staff);

        return $this->getJson('/api/v1/payroll/my-timesheet?year=2026&month=9')->assertOk()->json('data');
    }

    private function saveDay(string $uuid, string $date, array $entries)
    {
        Sanctum::actingAs($this->staff);

        return $this->putJson("/api/v1/payroll/my-timesheets/{$uuid}/days/{$date}", ['entries' => $entries]);
    }

    private function day(array $sheet, string $date): array
    {
        return collect($sheet['days'])->firstWhere('date', $date);
    }

    private function decide(?\App\Models\Payroll\Approval $approval, string $decision = 'approved')
    {
        Sanctum::actingAs($this->hr);

        return $this->postJson("/api/v1/payroll/approvals/{$approval->uuid}/decide", ['decision' => $decision]);
    }

    public function test_hours_are_split_into_normal_and_overtime_by_the_kind_of_day(): void
    {
        Holiday::create(['description' => 'A holiday', 'start_date' => '2026-09-07', 'end_date' => '2026-09-07']);
        $uuid = $this->sheet()['uuid'];

        // A weekday past the normal day: 8 normal, the rest weekday overtime.
        $sheet = $this->saveDay($uuid, '2026-08-24', [['work_site_uuid' => $this->base->uuid, 'hours' => 11]])->assertOk()->json('data');
        $this->assertEquals(['normal_hours' => 8, 'ot_weekday' => 3], (array) $this->day($sheet, '2026-08-24')['values']);

        // Two sites: the overtime lands on the later entry; the offshore tick counts once.
        $sheet = $this->saveDay($uuid, '2026-08-25', [
            ['work_site_uuid' => $this->base->uuid, 'hours' => 6],
            ['work_site_uuid' => $this->offshore->uuid, 'hours' => 5, 'values' => ['offshore' => true]],
        ])->assertOk()->json('data');
        $day = $this->day($sheet, '2026-08-25');
        $this->assertEquals(['offshore' => true, 'normal_hours' => 8, 'ot_weekday' => 3], (array) $day['values']);
        $this->assertSame([[6, 0], [2, 3]], array_map(fn ($e) => [(int) $e['normal_hours'], (int) $e['overtime_hours']], $day['entries']));

        // With times: hours outside 08:00–17:00 are overtime, the break comes out of the working day.
        $sheet = $this->saveDay($uuid, '2026-08-26', [['work_site_uuid' => $this->base->uuid, 'start_time' => '06:00', 'end_time' => '19:00', 'break_minutes' => 60]])->assertOk()->json('data');
        $this->assertEquals(['normal_hours' => 8, 'ot_weekday' => 4], (array) $this->day($sheet, '2026-08-26')['values']);
        $sheet = $this->saveDay($uuid, '2026-08-27', [['start_time' => '10:00', 'end_time' => '19:00', 'break_minutes' => 60]])->assertOk()->json('data');
        $this->assertEquals(['normal_hours' => 6, 'ot_weekday' => 2], (array) $this->day($sheet, '2026-08-27')['values']);
        // A night shift past midnight counts on the day it started, all outside the working day.
        $sheet = $this->saveDay($uuid, '2026-08-28', [['start_time' => '20:00', 'end_time' => '04:00']])->assertOk()->json('data');
        $this->assertEquals(['ot_weekday' => 8], (array) $this->day($sheet, '2026-08-28')['values']);

        // Weekends and holidays: all overtime of that kind.
        $sheet = $this->saveDay($uuid, '2026-08-22', [['work_site_uuid' => $this->base->uuid, 'hours' => 5]])->assertOk()->json('data');
        $this->assertEquals(['ot_weekend' => 5], (array) $this->day($sheet, '2026-08-22')['values']);
        $this->assertTrue($this->day($sheet, '2026-08-22')['needs_request']); // weekend overtime needs a request first
        $sheet = $this->saveDay($uuid, '2026-09-07', [['hours' => 4]])->assertOk()->json('data');
        $this->assertEquals(['ot_holiday' => 4], (array) $this->day($sheet, '2026-09-07')['values']);

        // Totals by column, and hours per site.
        $this->assertEquals(['normal_hours' => 30, 'ot_weekday' => 20, 'ot_weekend' => 5, 'ot_holiday' => 4, 'offshore' => 1], $sheet['totals']['columns']);
        $sites = collect($sheet['totals']['sites'])->keyBy('code');
        $this->assertEquals(['normal_hours' => 22, 'overtime_hours' => 12, 'days' => 4], collect($sites['TC'])->only(['normal_hours', 'overtime_hours', 'days'])->all());
        $this->assertEquals(['normal_hours' => 2, 'overtime_hours' => 3, 'days' => 1], collect($sites['MV25'])->only(['normal_hours', 'overtime_hours', 'days'])->all());
        // The FM-06 PDF lists every site worked that day.
        $letterhead = \App\Helpers\PdfLetterhead::get();
        $html = view('payroll.timesheet', [
            'sheet' => app(\App\Services\Payroll\TimesheetService::class)->present(Timesheet::where('uuid', $uuid)->first()),
            'decisions' => collect(), 'company' => $letterhead['company'], 'logo' => $letterhead['logo'],
        ])->render();
        $this->assertStringContainsString('TC / MV25', $html);
    }

    public function test_a_days_entries_are_checked(): void
    {
        $uuid = $this->sheet()['uuid'];
        $problem = fn (array $entries, string $text) => $this->saveDay($uuid, '2026-08-24', $entries)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, $text));

        $problem([['hours' => 14], ['hours' => 12]], 'more than 24 hours');
        $problem([['start_time' => '08:00', 'end_time' => '12:00'], ['start_time' => '11:00', 'end_time' => '15:00']], 'overlap');
        $problem([['start_time' => '08:00']], 'both a start and an end time');
        $problem([['start_time' => '08:00', 'end_time' => '09:00', 'break_minutes' => 60]], 'break is longer');
        $problem([['work_site_uuid' => $this->base->uuid, 'hours' => 8, 'values' => ['offshore' => true]]], 'needs an offshore site');

        // Empty entries are dropped; an empty list clears the day.
        $this->saveDay($uuid, '2026-08-24', [['hours' => 8], ['hours' => 0]])->assertOk();
        $this->assertSame(1, TimesheetEntry::count());
        $sheet = $this->saveDay($uuid, '2026-08-24', [])->assertOk()->json('data');
        $this->assertSame([], (array) $this->day($sheet, '2026-08-24')['values']);
    }

    public function test_fill_normal_days_fills_empty_working_days_only(): void
    {
        $uuid = $this->sheet()['uuid'];
        $this->saveDay($uuid, '2026-08-24', [['work_site_uuid' => $this->offshore->uuid, 'hours' => 10]])->assertOk();

        // 21 weekdays in 21 Aug – 20 Sep; one already has hours.
        $sheet = $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/fill", ['work_site_uuid' => $this->base->uuid])->assertOk()
            ->assertJsonPath('message', '20 days filled.')->json('data');
        $this->assertSame(21, $sheet['totals']['days_worked']);
        $this->assertSame([], (array) $this->day($sheet, '2026-08-22')['values']); // a Saturday
        $this->assertEquals(['normal_hours' => 8, 'ot_weekday' => 2], (array) $this->day($sheet, '2026-08-24')['values']);
        $this->assertSame('TC', $this->day($sheet, '2026-08-21')['entries'][0]['work_site']['code']);

        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/fill", ['work_site_uuid' => $this->base->uuid])->assertOk()
            ->assertJsonPath('message', 'Every working day already has something in it.');
    }

    public function test_approved_overtime_shows_up_on_the_timesheet_to_confirm(): void
    {
        $uuid = $this->sheet()['uuid'];
        Sanctum::actingAs($this->staff);
        $this->postJson('/api/v1/payroll/my-overtime', [
            'work_date' => '2026-09-05', 'hours' => 6, 'reason' => 'Vessel leaves Monday', 'activity_description' => 'Blasting',
            'work_site_uuid' => $this->offshore->uuid,
        ])->assertCreated();
        $request = OvertimeRequest::sole();
        $this->decide($request->approval)->assertOk();

        // Planned on Saturday 5 Sep, not counted until confirmed.
        $day = $this->day($this->sheet(), '2026-09-05');
        $this->assertTrue($day['has_planned']);
        $this->assertSame([6.0, true, 'MV25', $request->uuid], [(float) $day['entries'][0]['hours'], $day['entries'][0]['planned'], $day['entries'][0]['work_site']['code'], $day['entries'][0]['overtime_request']['uuid']]);
        $this->assertSame([], (array) $day['values']);
        $this->assertFalse($day['needs_request']);

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/fill", ['work_site_uuid' => $this->base->uuid])->assertOk();
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '5 Sep: confirm or remove the planned overtime'));

        // Unrequested weekend overtime is flagged and stops the sheet being sent.
        $sheet = $this->saveDay($uuid, '2026-09-06', [['hours' => 3]])->assertOk()->json('data');
        $this->assertTrue($this->day($sheet, '2026-09-06')['needs_request']);
        $this->saveDay($uuid, '2026-09-06', [])->assertOk();

        // Confirmed with the hours actually worked; the link stays.
        $entry = $day['entries'][0];
        $sheet = $this->saveDay($uuid, '2026-09-05', [['uuid' => $entry['uuid'], 'work_site_uuid' => $this->offshore->uuid, 'hours' => 5, 'planned' => false]])
            ->assertOk()->json('data');
        $this->assertEquals(['ot_weekend' => 5], (array) $this->day($sheet, '2026-09-05')['values']);

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk();
        $this->decide(Timesheet::where('uuid', $uuid)->first()->approval)->assertOk();

        $this->assertSame(5.0, (float) $request->fresh()->actual_hours);
        $this->assertSame(1, OvertimeRequest::count()); // no second request for the same hours
        Sanctum::actingAs($this->hr);
        $run = PayRun::where('uuid', $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->json('data.uuid'))->first();
        $this->postJson("/api/v1/payroll/runs/{$run->uuid}/calculate")->assertOk();
        $this->assertSame(5.0, (float) $run->payslips()->sole()->lines->firstWhere('code', 'OT_WE')->quantity); // the lower of 6 and 5

        // Confirming on the overtime page is for the timesheet now.
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-overtime/{$request->uuid}/actual-hours", ['actual_hours' => 4])->assertStatus(422);
    }

    public function test_removing_planned_overtime_means_none_was_worked(): void
    {
        $uuid = $this->sheet()['uuid'];
        Sanctum::actingAs($this->staff);
        $this->postJson('/api/v1/payroll/my-overtime', ['work_date' => '2026-09-05', 'hours' => 6, 'reason' => 'Urgent', 'activity_description' => 'Blasting'])->assertCreated();
        $request = OvertimeRequest::sole();
        $this->decide($request->approval)->assertOk();

        $this->saveDay($uuid, '2026-09-05', [])->assertOk();
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/fill")->assertOk();
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk();
        $this->decide(Timesheet::where('uuid', $uuid)->first()->approval)->assertOk();

        $this->assertSame(0.0, (float) $request->fresh()->actual_hours);
    }

    public function test_weekday_overtime_that_needs_no_request_is_claimed_by_the_sheet(): void
    {
        $uuid = $this->sheet()['uuid'];
        $sheet = $this->saveDay($uuid, '2026-08-24', [['work_site_uuid' => $this->base->uuid, 'hours' => 11]])->assertOk()->json('data');
        $this->assertFalse($this->day($sheet, '2026-08-24')['needs_request']);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk();
        $this->decide(Timesheet::where('uuid', $uuid)->first()->approval)->assertOk();

        $claim = OvertimeRequest::sole();
        $this->assertSame([3.0, 'approved', 'Weekday', $this->base->id], [(float) $claim->approved_hours, $claim->status, $claim->type->name, $claim->work_site_id]);
    }

    public function test_overtime_at_an_offshore_site_is_claimed_with_offshore_on_top(): void
    {
        Sanctum::actingAs($this->hr);
        $component = PayComponent::create(['code' => 'OT_OFF', 'name' => 'Offshore overtime', 'kind' => 'earning', 'calculation' => 'rate_per_unit', 'rate' => 10, 'unit' => 'hour', 'taxable' => true]);
        $this->postJson('/api/v1/payroll/overtime-types', ['name' => 'Offshore', 'pay_component_uuid' => $component->uuid, 'applies_on' => 'any'])->assertCreated();

        $uuid = $this->sheet()['uuid'];
        // 24 Aug: the overtime is worked offshore (the later entry). 25 Aug: offshore first, the overtime at the base.
        $this->saveDay($uuid, '2026-08-24', [['work_site_uuid' => $this->base->uuid, 'hours' => 6], ['work_site_uuid' => $this->offshore->uuid, 'hours' => 7]])->assertOk();
        $this->saveDay($uuid, '2026-08-25', [['work_site_uuid' => $this->offshore->uuid, 'hours' => 4], ['work_site_uuid' => $this->base->uuid, 'hours' => 8]])->assertOk();
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk();
        $this->decide(Timesheet::where('uuid', $uuid)->first()->approval)->assertOk();

        $claims = OvertimeRequest::with('type', 'addonType', 'workSite')->orderBy('work_date')->get();
        $this->assertSame(
            [['2026-08-24', 5.0, 'Weekday', 'Offshore', 'MV25'], ['2026-08-25', 4.0, 'Weekday', null, 'TC']],
            $claims->map(fn ($r) => [$r->work_date->toDateString(), (float) $r->approved_hours, $r->type->name, $r->addonType?->name, $r->workSite?->code])->all(),
        );
    }

    public function test_the_working_day_sets_the_hours_a_day(): void
    {
        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/payroll/settings', ['work_day_start' => '07:00', 'work_day_end' => '16:00', 'work_day_break_minutes' => 30])->assertOk()
            ->assertJsonPath('data.hours_per_day', 8.5);
        $this->putJson('/api/v1/payroll/settings', ['work_day_end' => '07:30'])->assertStatus(422);

        $uuid = $this->sheet()['uuid'];
        $sheet = $this->saveDay($uuid, '2026-08-24', [['hours' => 10]])->assertOk()->json('data');
        $this->assertEquals(['normal_hours' => 8.5, 'ot_weekday' => 1.5], (array) $this->day($sheet, '2026-08-24')['values']);
        $this->assertSame(['start' => '07:00', 'end' => '16:00', 'break_minutes' => 30, 'hours' => 8.5], $sheet['work_day']);
    }
}
