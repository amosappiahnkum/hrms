<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PayRunInputsTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private string $run;
    private $employee;
    private PayComponent $overtime;
    private PayComponent $bonus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['prepare-payroll', 'view-payroll']);
        Sanctum::actingAs($hr);

        $this->employee = $this->userWithRole('staff')->employee;
        $this->employee->update(['staff_id' => 'AI001', 'ssnit_number' => 'C1']);
        EmployeePayProfile::create(['employee_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'basic_salary' => 4400, 'payment_method' => 'cash']);
        $this->overtime = PayComponent::create(['code' => 'OT_WD', 'name' => 'Weekday overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 1.5, 'taxable' => true]);
        $this->bonus = PayComponent::create(['code' => 'BONUS', 'name' => 'Bonus', 'kind' => 'earning', 'calculation' => 'manual', 'taxable' => true]);
        $this->run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
    }

    private function add(array $data)
    {
        return $this->postJson("/api/v1/payroll/runs/{$this->run}/inputs", $data + ['employee_uuid' => $this->employee->uuid]);
    }

    public function test_inputs_are_checked_feed_the_calculation_and_put_a_calculated_run_back_to_draft(): void
    {
        $this->add(['pay_component_uuid' => $this->overtime->uuid])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'needs a quantity'));
        $this->add(['pay_component_uuid' => $this->bonus->uuid])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'needs an amount'));
        $this->add(['pay_component_uuid' => $this->overtime->uuid, 'quantity' => 10])->assertCreated();

        // 4,400 ÷ 22 ÷ 8 = 25 an hour; 10 h × 1.5 = 375.
        $this->postJson("/api/v1/payroll/runs/{$this->run}/calculate")->assertOk()->assertJsonPath('data.totals.gross_pay', 4775);
        $bonus = $this->add(['pay_component_uuid' => $this->bonus->uuid, 'amount' => 500, 'notes' => 'Project completion'])->assertCreated()->json('data.uuid');
        $this->assertSame('draft', PayRun::where('uuid', $this->run)->first()->status->value);
        $this->postJson("/api/v1/payroll/runs/{$this->run}/calculate")->assertOk()->assertJsonPath('data.totals.gross_pay', 5275);

        $this->putJson("/api/v1/payroll/runs/{$this->run}/inputs/{$bonus}", ['amount' => 600])->assertOk()->assertJsonPath('data.amount', 600);
        $this->getJson("/api/v1/payroll/runs/{$this->run}/inputs")->assertJsonCount(2, 'data');

        // Locked once sent for approval.
        $this->postJson("/api/v1/payroll/runs/{$this->run}/calculate")->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$this->run}/submit")->assertOk();
        $this->deleteJson("/api/v1/payroll/runs/{$this->run}/inputs/{$bonus}")->assertStatus(422);
    }

    public function test_an_import_saves_everything_or_nothing_and_lists_the_bad_rows(): void
    {
        $this->get('/api/v1/payroll/inputs-template')->assertOk();

        $file = function (array $rows) {
            $book = new Spreadsheet();
            $book->getActiveSheet()->fromArray([['Staff ID', 'Component code', 'Quantity', 'Amount', 'Notes'], ...$rows]);
            $path = tempnam(sys_get_temp_dir(), 'in') . '.xlsx';
            (new Xlsx($book))->save($path);

            return new UploadedFile($path, 'inputs.xlsx', null, null, true);
        };

        $this->post("/api/v1/payroll/runs/{$this->run}/inputs/import", ['file' => $file([
            ['AI001', 'OT_WD', 8, null, null],
            ['NOPE', 'OT_WD', 8, null, null],
            ['AI001', 'BONUS', null, null, null],
        ])], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.rows.3', fn ($e) => str_contains($e, 'Unknown employee'))
            ->assertJsonPath('errors.rows.4', fn ($e) => str_contains($e, 'needs an amount'));
        $this->getJson("/api/v1/payroll/runs/{$this->run}/inputs")->assertJsonCount(0, 'data');

        $this->post("/api/v1/payroll/runs/{$this->run}/inputs/import", ['file' => $file([
            ['AI001', 'OT_WD', 8, null, null],
            ['ai001', 'bonus', null, 250, 'Spot award'],
            [null, null, null, null, null],
        ])], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.created', 2);
    }
}
