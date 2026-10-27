<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Payroll\EmployeePayComponent;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\Payroll\Timesheet;
use App\Models\Payroll\TimeInput;
use App\Models\Payroll\WorkSite;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Daily timesheets (FM-06): filled in, checked, approved, and fed into pay. */
class TimesheetTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $staff;
    private WorkSite $offshore;
    private WorkSite $base;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 09:00:00');
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.timesheets', 'features.payroll.time_inputs', 'features.payroll.overtime'])
            ->update(['value' => true]);
        $settings = app(SettingService::class);
        $settings->refreshCache();
        $settings->set('payroll.period_start_day', 21); // September: 21 Aug – 20 Sep
        $settings->set('payroll.time_source', 'timesheet');
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'view-payroll', 'manage-timesheets', 'enter-time-inputs']);
        $this->staff = $this->userWithRole('staff');
        $this->staff->employee->update(['staff_id' => 'AI24028', 'ssnit_number' => 'C1']);
        EmployeePayProfile::create(['employee_id' => $this->staff->employee_id, 'effective_from' => '2026-01-01', 'basic_salary' => 4400, 'payment_method' => 'cash']);

        $this->offshore = WorkSite::create(['code' => 'MV25', 'name' => 'FPSO MV25', 'is_offshore' => true]);
        $this->base = WorkSite::create(['code' => 'TC', 'name' => 'Takoradi base']);
        PayComponent::create(['code' => 'OFFSHORE_DAY', 'name' => 'Offshore allowance', 'kind' => 'earning', 'calculation' => 'rate_per_unit', 'rate' => 100, 'unit' => 'day', 'taxable' => true]);
        $lunch = PayComponent::create(['code' => 'LUNCH', 'name' => 'Lunch', 'kind' => 'earning', 'calculation' => 'rate_per_unit', 'rate' => 70, 'unit' => 'day', 'quantity_source' => 'days_worked', 'recurring' => true, 'taxable' => true]);
        EmployeePayComponent::create(['employee_id' => $this->staff->employee_id, 'pay_component_id' => $lunch->id, 'effective_from' => '2026-01-01']);
        $ot = PayComponent::create(['code' => 'OT_WD', 'name' => 'Weekday overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 1.25, 'taxable' => true]);

        $settings->set('payroll.timesheet_columns', [
            ['key' => 'normal_hours', 'label' => 'Normal 8–5', 'type' => 'hours', 'sheet_column' => 'D'],
            ['key' => 'ot_weekday', 'label' => 'Overtime (Mon–Fri)', 'type' => 'hours', 'feeds' => 'overtime', 'day_kind' => 'weekday', 'sheet_column' => 'E'],
            ['key' => 'ot_weekend', 'label' => 'Weekend overtime', 'type' => 'hours', 'feeds' => 'overtime', 'day_kind' => 'weekend', 'sheet_column' => 'F'],
            ['key' => 'offshore', 'label' => 'Offshore days', 'type' => 'check', 'feeds' => 'time_input', 'component' => 'OFFSHORE_DAY', 'requires_offshore' => true, 'sheet_column' => 'H'],
        ]);

        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/overtime-types', ['name' => 'Weekday', 'pay_component_uuid' => $ot->uuid, 'applies_on' => 'weekday'])->assertCreated();
        // HR approves timesheets.
        $this->postJson('/api/v1/payroll/approval-workflows', [
            'process' => 'timesheet', 'name' => 'Timesheets', 'is_default' => true,
            'steps' => [['name' => 'Supervisor', 'approver_type' => 'permission', 'approver_value' => 'configure-payroll']],
        ])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mine(): array
    {
        Sanctum::actingAs($this->staff);

        return $this->getJson('/api/v1/payroll/my-timesheet?year=2026&month=9')->assertOk()->json('data');
    }

    private function fill(string $uuid, array $days)
    {
        Sanctum::actingAs($this->staff);

        return $this->putJson("/api/v1/payroll/my-timesheets/{$uuid}", ['days' => $days]);
    }

    /** Normal hours on the weekdays given, offshore at MV25 on some, weekday overtime on one. */
    private function filled(): string
    {
        $uuid = $this->mine()['uuid'];
        $days = [];
        foreach (['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28'] as $d) {
            $days[] = ['date' => $d, 'values' => ['normal_hours' => 8], 'work_site_uuid' => $this->base->uuid];
        }
        foreach (['2026-08-31', '2026-09-01', '2026-09-02'] as $d) {
            $days[] = ['date' => $d, 'values' => ['normal_hours' => 8, 'offshore' => true], 'work_site_uuid' => $this->offshore->uuid];
        }
        $days[] = ['date' => '2026-09-03', 'values' => ['normal_hours' => 8, 'ot_weekday' => 3], 'work_site_uuid' => $this->base->uuid];
        $this->fill($uuid, $days)->assertOk();

        return $uuid;
    }

    private function decide(string $uuid, string $decision, ?string $comment = null)
    {
        Sanctum::actingAs($this->hr);
        $approval = Timesheet::where('uuid', $uuid)->first()->approval;

        return $this->postJson("/api/v1/payroll/approvals/{$approval->uuid}/decide", ['decision' => $decision, 'comment' => $comment]);
    }

    private function payslip()
    {
        Sanctum::actingAs($this->hr);
        $run = PayRun::where(['year' => 2026, 'month' => 9])->first()
            ?? PayRun::where('uuid', $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->json('data.uuid'))->first();
        $this->postJson("/api/v1/payroll/runs/{$run->uuid}/calculate")->assertOk();

        return $run->payslips()->where('employee_id', $this->staff->employee_id)->sole();
    }

    public function test_a_sheet_has_a_row_per_day_of_the_pay_period_with_weekends_holidays_and_leave(): void
    {
        Holiday::create(['description' => 'Founders\' Day', 'start_date' => '2026-08-04', 'end_date' => '2026-08-04']);
        Holiday::create(['description' => 'Kwame Nkrumah Day', 'start_date' => '2026-09-21', 'end_date' => '2026-09-21']);
        Holiday::create(['description' => 'A test holiday', 'start_date' => '2026-09-07', 'end_date' => '2026-09-07']);
        $type = DB::table('leave_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Annual', 'entitlement_type' => 'fixed', 'number_of_days' => 20,
            'start_of_annual_cycle' => '2026-01-01', 'request_type' => 'days', 'user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employee = $this->staff->employee;
        LeaveRequest::create([
            'employee_id' => $employee->id, 'supervisor_id' => $employee->id, 'department_id' => $employee->department_id, 'leave_type_id' => $type,
            'days_requested' => 2, 'reason' => 'Rest', 'status' => 'hr_approved', 'start_date' => '2026-09-10', 'end_date' => '2026-09-11', 'user_id' => $this->hr->id,
        ]);

        $sheet = $this->mine();

        $this->assertSame(['2026-08-21', '2026-09-20'], [$sheet['period_start'], $sheet['period_end']]);
        $days = collect($sheet['days'])->keyBy('date');
        $this->assertCount(31, $days);
        $this->assertSame('weekend', $days['2026-08-22']['kind']);
        $this->assertSame(['holiday', 'A test holiday'], [$days['2026-09-07']['kind'], $days['2026-09-07']['holiday']]);
        $this->assertTrue($days['2026-09-10']['is_leave']);
        $this->assertFalse($days['2026-09-09']['is_leave']);
        $this->assertTrue($sheet['can']['edit']);

        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/payroll/timesheets?year=2026&month=9')->assertOk()
            ->assertJsonPath('data.summary.draft', 1)
            ->assertJsonPath('data.rows.0.timesheet.status', 'draft');
    }

    public function test_days_are_checked_against_the_rules(): void
    {
        $uuid = $this->mine()['uuid'];

        // Hours are split by the kind of day, whatever column they came in: on a weekend they're weekend overtime.
        $this->fill($uuid, [['date' => '2026-08-22', 'values' => ['ot_weekday' => 2]]])->assertOk()
            ->assertJsonPath('data.days.1.values', ['ot_weekend' => 2]);
        $this->fill($uuid, [['date' => '2026-08-24', 'values' => ['offshore' => true], 'work_site_uuid' => $this->base->uuid]])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'needs an offshore site'));
        $this->fill($uuid, [['date' => '2026-08-24', 'values' => ['normal_hours' => 20, 'ot_weekday' => 6]]])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'more than 24 hours'));
        $this->fill($uuid, [['date' => '2026-09-25', 'values' => ['normal_hours' => 8]]])->assertStatus(422);

        // Requested before the work: overtime only on days with an approved request (checked when it's sent).
        app(SettingService::class)->set('payroll.overtime_mode', 'before');
        $this->fill($uuid, [['date' => '2026-09-03', 'values' => ['normal_hours' => 8, 'ot_weekday' => 3]]])->assertOk()
            ->assertJsonPath('data.days.13.needs_request', true);
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '3 Sep: there\'s no approved overtime request'));
        $this->fill($uuid, [['date' => '2026-09-03', 'values' => []]])->assertOk();

        $this->fill($uuid, [['date' => '2026-08-24', 'values' => ['normal_hours' => 8, 'unknown' => 5]]])->assertOk()
            ->assertJsonPath('data.days.3.values.normal_hours', 8)
            ->assertJsonMissingPath('data.days.3.values.unknown');
    }

    public function test_a_rejected_sheet_reopens_with_the_reason_and_an_approved_one_is_locked(): void
    {
        $uuid = $this->filled();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->fill($uuid, [['date' => '2026-08-24', 'values' => ['normal_hours' => 7]]])->assertStatus(422);

        $this->decide($uuid, 'rejected', 'Fill in 4 Sep')->assertOk();
        $sheet = $this->mine();
        $this->assertSame(['rejected', 'Fill in 4 Sep', true], [$sheet['status'], $sheet['rejection_reason'], $sheet['editable']]);
        $this->assertNotNull($sheet['rejected_at']);

        $this->fill($uuid, [['date' => '2026-09-04', 'values' => ['normal_hours' => 8]]])->assertOk();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk();
        $this->decide($uuid, 'approved')->assertOk();

        $this->assertSame('approved', $this->mine()['status']);
        $this->fill($uuid, [['date' => '2026-09-04', 'values' => ['normal_hours' => 4]]])->assertStatus(422);
    }

    public function test_the_employee_takes_a_waiting_sheet_back_to_correct_it(): void
    {
        $uuid = $this->filled();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk()->assertJsonPath('data.can.withdraw', true);

        // Someone else's sheet: not theirs to take back.
        Sanctum::actingAs($this->userWithRole('staff'));
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/withdraw")->assertNotFound();

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/withdraw")->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.editable', true)->assertJsonPath('data.can.withdraw', false);
        $this->assertSame('cancelled', \App\Models\Payroll\Timesheet::where('uuid', $uuid)->sole()->approval->status);
        // The approver whose turn it was is told it was taken back; the employee isn't.
        Notification::assertSentTo($this->hr, \App\Notifications\ApprovalWithdrawnNotification::class);
        Notification::assertNotSentTo($this->staff, \App\Notifications\ApprovalWithdrawnNotification::class);
        Sanctum::actingAs($this->hr);
        $this->assertSame(0, $this->getJson('/api/v1/payroll/approvals')->json('data.meta.waiting_count'));

        // Corrected and sent again: a fresh approval; once approved it can't be taken back.
        $this->fill($uuid, [['date' => '2026-09-04', 'values' => ['normal_hours' => 8]]])->assertOk();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk();
        $this->decide($uuid, 'approved')->assertOk();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/withdraw")->assertStatus(422);
    }

    public function test_an_approved_sheet_feeds_pay_once(): void
    {
        $uuid = $this->filled();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk();
        $this->decide($uuid, 'approved')->assertOk();

        // 9 days worked, 3 offshore, 3 h weekday overtime (claimed through the timesheet).
        $this->assertSame(3.0, (float) TimeInput::whereHas('component', fn ($c) => $c->where('code', 'OFFSHORE_DAY'))->sole()->quantity);
        $this->assertSame(9.0, (float) TimeInput::whereHas('component', fn ($c) => $c->where('code', PayComponent::DAYS_WORKED))->sole()->quantity);
        $this->assertSame(3.0, (float) OvertimeRequest::sole()->approved_hours);

        $lines = $this->payslip()->lines->keyBy('code');
        $this->assertSame(630.0, (float) $lines['LUNCH']->amount);          // 9 × 70
        $this->assertSame(300.0, (float) $lines['OFFSHORE_DAY']->amount);   // 3 × 100
        $this->assertSame(3.0, (float) $lines['OT_WD']->quantity);

        // Reopened, corrected and approved again: replaced, not added.
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/payroll/timesheets/{$uuid}/reopen")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertSame(0, TimeInput::count());
        $this->assertSame(0, OvertimeRequest::count());
        $this->putJson("/api/v1/payroll/timesheets/{$uuid}", ['days' => [['date' => '2026-09-04', 'values' => ['normal_hours' => 8]]]])->assertOk();
        $this->postJson("/api/v1/payroll/timesheets/{$uuid}/submit")->assertOk();
        $this->decide($uuid, 'approved')->assertOk();

        $this->assertSame(1, TimeInput::whereHas('component', fn ($c) => $c->where('code', PayComponent::DAYS_WORKED))->count());
        $this->assertSame(10.0, (float) TimeInput::whereHas('component', fn ($c) => $c->where('code', PayComponent::DAYS_WORKED))->sole()->quantity);
        $this->assertSame(1, OvertimeRequest::count());
        $this->assertSame(700.0, (float) $this->payslip()->lines->firstWhere('code', 'LUNCH')->amount);

        // Once the run is sent for approval, the sheet can't be reopened.
        PayRun::query()->update(['status' => 'pending_approval']);
        $this->postJson("/api/v1/payroll/timesheets/{$uuid}/reopen")->assertStatus(422);
    }

    public function test_it_confirms_the_hours_worked_on_overtime_approved_in_advance(): void
    {
        app(SettingService::class)->set('payroll.overtime_mode', 'both');
        $request = OvertimeRequest::create([
            'employee_id' => $this->staff->employee_id, 'overtime_type_id' => \App\Models\Payroll\OvertimeType::value('id'), 'work_date' => '2026-09-03',
            'hours_requested' => 4, 'approved_hours' => 4, 'status' => 'approved', 'requested_in_advance' => true, 'activity_description' => 'Inspection',
        ]);
        $uuid = $this->filled();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk();
        $this->decide($uuid, 'approved')->assertOk();

        $this->assertSame(3.0, (float) $request->fresh()->actual_hours);
        $this->assertSame(1, OvertimeRequest::count());
        $this->assertSame(3.0, (float) $this->payslip()->lines->firstWhere('code', 'OT_WD')->quantity); // the lower of 4 and 3
    }

    public function test_someone_with_a_sheet_but_no_pay_details_is_listed_and_flagged(): void
    {
        $newcomer = $this->userWithRole('staff'); // no pay details yet
        $nobody = $this->userWithRole('staff');   // no pay details, no sheet
        Sanctum::actingAs($newcomer);
        $this->getJson('/api/v1/payroll/my-timesheet?year=2026&month=9')->assertOk();

        Sanctum::actingAs($this->hr);
        $rows = collect($this->getJson('/api/v1/payroll/timesheets?year=2026&month=9')->assertOk()->json('data.rows'))->keyBy('employee.uuid');
        $this->assertTrue($rows[$newcomer->employee->uuid]['no_pay_details']);
        $this->assertNotNull($rows[$newcomer->employee->uuid]['timesheet']);
        $this->assertFalse($rows[$this->staff->employee->uuid]['no_pay_details']);
        $this->assertArrayNotHasKey($nobody->employee->uuid, $rows->all());
    }

    public function test_the_run_notes_who_has_no_approved_timesheet(): void
    {
        $this->assertContains('No approved timesheet for this month.', $this->payslip()->warnings);

        app(SettingService::class)->set('payroll.time_source', 'manual');
        $this->assertNotContains('No approved timesheet for this month.', $this->payslip()->warnings);
    }

    public function test_a_filled_fm06_is_imported(): void
    {
        Sanctum::actingAs($this->hr);
        $uuid = $this->postJson('/api/v1/payroll/timesheets?year=2026&month=9', ['employee_uuid' => $this->staff->employee->uuid])->assertCreated()->json('data.uuid');

        // FM-06: row 10 is the period's first day (21 Aug); D normal, E overtime, H offshore, L site.
        $book = new Spreadsheet();
        $cells = $book->getActiveSheet();
        $cells->setCellValue('D13', '√')->setCellValue('L13', 'TC');                              // Mon 24 Aug
        $cells->setCellValue('D14', '√')->setCellValue('H14', '√')->setCellValue('L14', 'mv25');   // Tue 25 Aug
        $cells->setCellValue('D15', '-')->setCellValue('E15', '-')->setCellValue('L15', '-');      // nothing
        $path = storage_path('framework/testing-fm06.xlsx');
        (new Xlsx($book))->save($path);

        $sheet = $this->postJson("/api/v1/payroll/timesheets/{$uuid}/import", ['file' => new UploadedFile($path, 'fm06.xlsx', null, null, true)])
            ->assertOk()->json('data');
        @unlink($path);

        $days = collect($sheet['days'])->keyBy('date');
        $this->assertSame(['normal_hours' => 8], (array) $days['2026-08-24']['values']);
        $this->assertEquals(['normal_hours' => 8, 'offshore' => true], (array) $days['2026-08-25']['values']);
        $this->assertSame('MV25', $days['2026-08-25']['work_site']['code']);
        $this->assertSame([], (array) $days['2026-08-26']['values']);
        $this->assertSame(2, $sheet['totals']['days_worked']);
    }

    public function test_the_employee_hr_and_approvers_see_the_sheet_and_its_pdf_others_dont(): void
    {
        $uuid = $this->filled();
        $this->getJson("/api/v1/payroll/timesheets/{$uuid}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        $other = $this->userWithRole('staff');
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/payroll/timesheets/{$uuid}")->assertForbidden();
        $this->getJson('/api/v1/payroll/timesheets?year=2026&month=9')->assertForbidden();

        Sanctum::actingAs($this->hr);
        $this->getJson("/api/v1/payroll/timesheets/{$uuid}")->assertOk()->assertJsonPath('data.can.edit', true)->assertJsonPath('data.can.decide', false);

        // Sent: the approver whose turn it is can decide it from the sheet (the employee can't).
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk();
        $this->getJson("/api/v1/payroll/timesheets/{$uuid}")->assertJsonPath('data.can.decide', false);
        Sanctum::actingAs($this->hr);
        $this->getJson("/api/v1/payroll/timesheets/{$uuid}")->assertJsonPath('data.can.decide', true);
        $this->getJson('/api/v1/payroll/approvals')->assertJsonPath('data.data.0.can_decide', true)
            ->assertJsonPath('data.data.0.link', "/payroll/timesheets/{$uuid}"); // HR stays in the payroll app
    }
}
