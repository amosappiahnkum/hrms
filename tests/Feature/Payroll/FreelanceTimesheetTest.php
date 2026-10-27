<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\Client;
use App\Models\Payroll\EmployeePayComponent;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\Payroll\Timesheet;
use App\Models\Payroll\TimeInput;
use App\Models\Payroll\WorkOrder;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Freelance timesheets: one per client / work order a month, signed by the client, paid at day rates. */
class FreelanceTimesheetTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $freelancer;
    private WorkOrder $po;
    private Client $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28 09:00:00');
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.timesheets', 'features.payroll.time_inputs'])->update(['value' => true]);
        $settings = app(SettingService::class);
        $settings->refreshCache();
        $settings->set('payroll.time_source', 'timesheet');
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'view-payroll', 'manage-timesheets']);
        $this->freelancer = $this->userWithRole('staff');
        $this->freelancer->employee->update(['job_type' => 'freelance', 'staff_id' => 'FL001']);
        EmployeePayProfile::create(['employee_id' => $this->freelancer->employee_id, 'effective_from' => '2026-01-01', 'basic_salary' => 0, 'payment_method' => 'cash']);

        foreach (['WORKING_DAY' => 300, 'TRAVEL_DAY' => 150] as $code => $rate) {
            $c = PayComponent::create(['code' => $code, 'name' => ucwords(strtolower(str_replace('_', ' ', $code))), 'kind' => 'earning', 'calculation' => 'rate_per_unit', 'rate' => $rate, 'unit' => 'day', 'recurring' => true, 'taxable' => true]);
            EmployeePayComponent::create(['employee_id' => $this->freelancer->employee_id, 'pay_component_id' => $c->id, 'effective_from' => '2026-01-01']);
        }
        $settings->set('payroll.freelance_timesheet_columns', [
            ['key' => 'working_day', 'label' => 'Working day', 'type' => 'check', 'feeds' => 'time_input', 'component' => 'WORKING_DAY'],
            ['key' => 'standby_day', 'label' => 'Standby day', 'type' => 'check'],
            ['key' => 'travel_day', 'label' => 'Travel day', 'type' => 'check', 'feeds' => 'time_input', 'component' => 'TRAVEL_DAY'],
        ]);
        $settings->set('payroll.freelance_worked_columns', ['working_day']);

        $tullow = Client::create(['name' => 'Tullow Oil – Ghana']);
        $this->po = WorkOrder::create(['number' => 'PO# 4500028551', 'client_id' => $tullow->id, 'description' => 'NDT inspection on IP COT']);
        $this->other = Client::create(['name' => 'Another client']);

        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/approval-workflows', [
            'process' => 'timesheet', 'name' => 'Timesheets', 'is_default' => true,
            'steps' => [['name' => 'Operations Manager', 'approver_type' => 'permission', 'approver_value' => 'configure-payroll']],
        ])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function start(array $data)
    {
        Sanctum::actingAs($this->freelancer);

        return $this->postJson('/api/v1/payroll/my-timesheets?year=2026&month=9', $data);
    }

    private function fillAndApprove(string $uuid, array $days): void
    {
        Sanctum::actingAs($this->freelancer);
        $this->putJson("/api/v1/payroll/my-timesheets/{$uuid}", ['days' => array_map(fn ($date, $values) => ['date' => $date, 'values' => $values], array_keys($days), $days)])->assertOk();
        $this->postJson("/api/v1/payroll/my-timesheets/{$uuid}/submit")->assertOk();
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/approvals/' . Timesheet::where('uuid', $uuid)->first()->approval->uuid . '/decide', ['decision' => 'approved'])->assertOk();
    }

    public function test_a_freelancer_has_a_sheet_per_client_or_work_order_a_month(): void
    {
        $sheet = $this->start(['work_order_uuid' => $this->po->uuid])->assertCreated()
            ->assertJsonPath('data.template', 'freelance')
            ->assertJsonPath('data.client.name', 'Tullow Oil – Ghana')
            ->assertJsonPath('data.work_order.number', 'PO# 4500028551')
            ->assertJsonPath('data.task_description', 'NDT inspection on IP COT')
            ->assertJsonPath('data.columns.0.key', 'working_day')
            ->json('data');
        $this->assertSame($sheet['uuid'], $this->start(['work_order_uuid' => $this->po->uuid])->json('data.uuid'));
        $this->start(['client_uuid' => $this->other->uuid, 'task_description' => 'Rope access'])->assertCreated();

        Sanctum::actingAs($this->freelancer);
        $this->getJson('/api/v1/payroll/my-timesheets?year=2026&month=9')->assertOk()
            ->assertJsonPath('data.is_freelance', true)->assertJsonCount(2, 'data.timesheets');

        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/payroll/timesheets?year=2026&month=9')->assertOk()
            ->assertJsonPath('data.rows.0.is_freelance', true)->assertJsonCount(2, 'data.rows.0.timesheets');

        // Staff use the monthly sheet.
        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);
        $this->postJson('/api/v1/payroll/my-timesheets?year=2026&month=9', ['client_uuid' => $this->other->uuid])->assertStatus(422);
    }

    public function test_the_clients_sign_off_is_recorded_with_a_scan(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn ($path) => "https://files.test/{$path}");
        $uuid = $this->start(['work_order_uuid' => $this->po->uuid])->json('data.uuid');

        $this->post("/api/v1/payroll/my-timesheets/{$uuid}/details", [
            'client_rep_name' => 'Moya Ejiro', 'client_signed_on' => '2026-09-30', 'signoff' => UploadedFile::fake()->create('signed.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.client_rep_name', 'Moya Ejiro')
            ->assertJsonPath('data.client_signed_on', '2026-09-30')
            ->assertJsonPath('data.has_signoff_scan', true);

        $path = Timesheet::where('uuid', $uuid)->value('client_signoff_path');
        Storage::disk('s3')->assertExists($path);
        $this->getJson("/api/v1/payroll/timesheets/{$uuid}/signoff")->assertOk()->assertJsonPath('data.url', "https://files.test/{$path}");
        $this->getJson("/api/v1/payroll/timesheets/{$uuid}/pdf")->assertOk();
    }

    public function test_approved_sheets_add_up_to_the_months_pay_and_one_can_be_withdrawn(): void
    {
        $first = $this->start(['work_order_uuid' => $this->po->uuid])->json('data.uuid');
        $second = $this->start(['client_uuid' => $this->other->uuid])->json('data.uuid');
        // 17 Sep travel; 18–20 Sep working at the PO; 1–2 Sep working for the other client.
        $this->fillAndApprove($first, ['2026-09-17' => ['travel_day' => true], '2026-09-18' => ['working_day' => true], '2026-09-19' => ['working_day' => true], '2026-09-20' => ['working_day' => true]]);
        $this->fillAndApprove($second, ['2026-09-01' => ['working_day' => true], '2026-09-02' => ['working_day' => true]]);

        $working = TimeInput::whereHas('component', fn ($c) => $c->where('code', 'WORKING_DAY'))->pluck('quantity')->map(fn ($q) => (float) $q)->sort()->values()->all();
        $this->assertSame([2.0, 3.0], $working);

        Sanctum::actingAs($this->hr);
        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk();
        $lines = PayRun::where('uuid', $run)->first()->payslips()->sole()->lines->keyBy('code');
        $this->assertSame([5.0, 1500.0], [(float) $lines['WORKING_DAY']->quantity, (float) $lines['WORKING_DAY']->amount]);
        $this->assertSame(150.0, (float) $lines['TRAVEL_DAY']->amount);

        // Reopening one withdraws only what it fed.
        $this->postJson("/api/v1/payroll/timesheets/{$second}/reopen")->assertOk();
        $this->assertSame([3.0], TimeInput::whereHas('component', fn ($c) => $c->where('code', 'WORKING_DAY'))->pluck('quantity')->map(fn ($q) => (float) $q)->all());
    }
}
