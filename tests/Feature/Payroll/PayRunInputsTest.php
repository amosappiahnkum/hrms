<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PayRunInputsTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess, \Tests\Support\FillsGridTemplates;

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

    public function test_the_runs_sheet_is_filled_in_and_imported_all_or_nothing(): void
    {
        $this->add(['pay_component_uuid' => $this->bonus->uuid, 'amount' => 100])->assertCreated();
        $template = fn () => $this->downloaded($this->get("/api/v1/payroll/runs/{$this->run}/inputs/template"));

        // The sheet holds what's entered; reference cells are locked, the rows and columns identified by hidden refs.
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($template())->getSheet(0);
        $this->assertSame("pay-run:{$this->run}", $sheet->getCell('A1')->getValue());
        $this->assertFalse($sheet->getColumnDimension('A')->getVisible());
        $this->assertFalse($sheet->getRowDimension(1)->getVisible());

        // A bonus without an amount is fine (it's emptied = removed); hours must be numbers.
        $import = fn ($file) => $this->post("/api/v1/payroll/runs/{$this->run}/inputs/import", ['file' => $file], ['Accept' => 'application/json']);
        $import($this->fillGrid($template(), fn ($staff) => $staff === 'AI001' ? ['Weekday overtime' => 'eight'] : null))
            ->assertStatus(422)->assertJsonPath('errors.rows', fn ($rows) => str_contains(collect($rows)->first(), 'must be a number'));
        $this->getJson("/api/v1/payroll/runs/{$this->run}/inputs")->assertJsonCount(1, 'data');

        $import($this->fillGrid($template(), fn ($staff) => $staff === 'AI001' ? ['Weekday overtime' => 8, 'Bonus' => 250, 'Notes' => 'Spot award'] : null))
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);
        $inputs = collect($this->getJson("/api/v1/payroll/runs/{$this->run}/inputs")->json('data'));
        $this->assertSame(250.0, (float) $inputs->firstWhere('component.code', 'BONUS')['amount']);

        // Emptying a cell removes the input; the same sheet again changes nothing.
        $import($this->fillGrid($template(), fn ($staff) => $staff === 'AI001' ? ['Bonus' => null] : null))->assertOk()->assertJsonPath('data.removed', 1);
        $import($this->fillGrid($template(), fn () => null))->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Nothing to change'));

        // Another run's sheet is refused.
        $other = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 7])->json('data.uuid');
        $this->post("/api/v1/payroll/runs/{$other}/inputs/import", ['file' => $this->fillGrid($template(), fn () => null)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.rows.1', fn ($m) => str_contains($m, 'another pay run'));
    }

    public function test_payslips_filter_by_what_they_were_calculated_with_and_total_the_filter(): void
    {
        $other = $this->userWithRole('staff')->employee;
        $other->update(['staff_id' => 'AI002', 'ssnit_number' => 'C2']);
        EmployeePayProfile::create(['employee_id' => $other->id, 'effective_from' => '2026-01-01', 'basic_salary' => 3000, 'payment_method' => 'bank', 'bank_name' => 'GCB', 'account_number' => '1']);
        $this->add(['pay_component_uuid' => $this->overtime->uuid, 'quantity' => 10])->assertCreated();
        $this->postJson("/api/v1/payroll/runs/{$this->run}/calculate")->assertOk();
        $list = fn (string $query = '') => $this->getJson("/api/v1/payroll/runs/{$this->run}/payslips?{$query}")->assertOk();

        $all = $list();
        $this->assertSame(['GCB'], $all->json('data.filters.banks'));
        $this->assertContains('OT_WD', array_column($all->json('data.filters.components'), 'value'));

        $list('component=OT_WD')->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.employee.staff_id', 'AI001');
        $list('payment_method=bank')->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.totals.payslips', 1);
        $list('bank=GCB')->assertJsonPath('data.data.0.employee.staff_id', 'AI002');
        $list('sort=net_asc')->assertJsonPath('data.data.0.employee.staff_id', 'AI002');
        $this->assertSame(round((float) $all->json('data.totals.gross_pay'), 2), round(4775 + 3000, 2));
    }
}
