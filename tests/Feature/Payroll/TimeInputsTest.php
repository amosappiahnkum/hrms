<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\Payroll\TimeInput;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class TimeInputsTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess, \Tests\Support\FillsGridTemplates;

    private User $hr;
    private User $approver;
    private $employee;
    private PayComponent $offshore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.time_inputs'])->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['enter-time-inputs', 'prepare-payroll', 'view-payroll']);
        // With no workflow, people who configure payroll approve.
        $this->approver = $this->userWithRole('hr');
        $this->approver->givePermissionTo('configure-payroll');
        $this->employee = $this->userWithRole('staff')->employee;
        $this->employee->update(['staff_id' => 'AI001', 'ssnit_number' => 'C1']);
        EmployeePayProfile::create(['employee_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'basic_salary' => 4400, 'payment_method' => 'cash']);
        $this->offshore = PayComponent::create(['code' => 'OFFSHORE', 'name' => 'Offshore day', 'kind' => 'earning', 'calculation' => 'rate_per_unit', 'rate' => 100, 'unit' => 'days', 'taxable' => true]);
        Sanctum::actingAs($this->hr);
    }

    private function enter(?float $quantity, int $month = 6)
    {
        return $this->putJson('/api/v1/payroll/time-inputs', [
            'employee_uuid' => $this->employee->uuid, 'pay_component_uuid' => $this->offshore->uuid, 'year' => 2026, 'month' => $month, 'quantity' => $quantity,
        ]);
    }

    public function test_inputs_are_entered_approved_and_paid_by_the_run_once(): void
    {
        $this->getJson('/api/v1/payroll/time-inputs?year=2026&month=6')->assertOk()->assertJsonPath('data.component', $this->offshore->uuid);
        $this->enter(12)->assertOk()->assertJsonPath('data.status', 'pending');
        // A change replaces the entry and its approval.
        $this->enter(14)->assertOk();
        $this->assertSame(1, TimeInput::whereIn('status', ['pending', 'approved'])->count());
        $this->getJson('/api/v1/payroll/time-inputs?year=2026&month=6&entered_only=1')
            ->assertJsonPath('data.summary.quantity', 14)->assertJsonPath('data.rows.0.entry.quantity', 14);

        // Not approved yet: the run doesn't pay it.
        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertJsonPath('data.totals.gross_pay', 4400);

        Sanctum::actingAs($this->approver);
        $approval = $this->getJson('/api/v1/payroll/approvals')->assertJsonPath('data.meta.waiting_count', 1)->json('data.data.0');
        $this->assertSame('quantity', $approval['adjustable'][0]['field']);
        $this->postJson("/api/v1/payroll/approvals/{$approval['uuid']}/decide", ['decision' => 'approved', 'adjustments' => ['quantity' => 13]])->assertOk();

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertJsonPath('data.totals.gross_pay', 5700);
        $this->getJson("/api/v1/payroll/runs/{$run}/inputs")->assertJsonPath('data.0.source', 'time_input');

        // Once the run is sent for approval, it can't change.
        $this->postJson("/api/v1/payroll/runs/{$run}/submit")->assertOk();
        $this->enter(20)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'has been paid'));

        // In the month's sheet, that cell is locked.
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->downloaded($this->get('/api/v1/payroll/time-inputs/template?year=2026&month=6')))->getSheet(0);
        $row = collect(range(3, $sheet->getHighestRow()))->first(fn ($r) => $sheet->getCell("B{$r}")->getValue() === 'AI001');
        $this->assertSame(13.0, (float) $sheet->getCell("E{$row}")->getValue());
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_PROTECTED, $sheet->getStyle("E{$row}")->getProtection()->getLocked());
    }

    public function test_without_approval_inputs_count_as_entered_and_imports_are_all_or_nothing(): void
    {
        $this->putJson('/api/v1/payroll/settings', ['time_inputs_require_approval' => false])->assertForbidden();
        Setting::where('key', 'payroll.time_inputs_require_approval')->update(['value' => false]);
        app(SettingService::class)->refreshCache();

        $this->enter(5)->assertJsonPath('data.status', 'approved');
        $this->enter(0)->assertOk()->assertJsonPath('data', null);
        $this->assertSame(0, TimeInput::count());

        // The month's sheet: everyone by every per-unit component, filled with what's entered.
        $template = fn (int $month = 6) => $this->downloaded($this->get("/api/v1/payroll/time-inputs/template?year=2026&month={$month}"));
        $import = fn ($file, int $month = 6) => $this->post('/api/v1/payroll/time-inputs/import', ['year' => 2026, 'month' => $month, 'file' => $file], ['Accept' => 'application/json']);

        $import($this->fillGrid($template(), fn ($staff) => $staff === 'AI001' ? ['Offshore day' => 'ten'] : null))
            ->assertStatus(422)->assertJsonPath('errors.rows', fn ($rows) => str_contains(collect($rows)->first(), 'must be a number'));
        $this->assertSame(0, TimeInput::count());

        // Checking first shows the change (and a warning for more days than June has) without saving.
        $sheet = $this->fillGrid($template(), fn ($staff) => $staff === 'AI001' ? ['Offshore day' => 31] : null);
        $this->post('/api/v1/payroll/time-inputs/import', ['year' => 2026, 'month' => 6, 'file' => $sheet, 'check' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.changes.0.fields.0.to', '31 days')
            ->assertJsonPath('data.changes.0.warnings.0', fn ($w) => str_contains($w, "more than the month's 30"));
        $this->assertSame(0, TimeInput::count());

        $import($this->fillGrid($template(), fn ($staff) => $staff === 'AI001' ? ['Offshore day' => 8] : null))
            ->assertOk()->assertJsonPath('data.saved', 1);
        $this->assertSame('8.00', TimeInput::sole()->quantity);

        // June's sheet can't be imported into July.
        $import($this->fillGrid($template(), fn () => null), 7)->assertStatus(422)->assertJsonPath('errors.rows.1', fn ($m) => str_contains($m, 'June 2026'));

        // Emptying the cell removes the entry.
        $import($this->fillGrid($template(), fn ($staff) => $staff === 'AI001' ? ['Offshore day' => null] : null))->assertOk()->assertJsonPath('data.removed', 1);
        $this->assertSame(0, TimeInput::whereIn('status', ['pending', 'approved'])->count());
    }
}
