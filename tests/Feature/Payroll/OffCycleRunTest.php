<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\StatutoryRateSet;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class OffCycleRunTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_an_off_cycle_bonus_run_pays_only_the_bonus_taxed_on_top_of_the_month(): void
    {
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['prepare-payroll', 'configure-payroll']);
        Sanctum::actingAs($hr);

        foreach (['A1', 'A2'] as $staffId) {
            $e = $this->userWithRole('staff')->employee;
            $e->update(['staff_id' => $staffId, 'ssnit_number' => 'C1']);
            EmployeePayProfile::create(['employee_id' => $e->id, 'effective_from' => '2026-01-01', 'basic_salary' => 5000, 'payment_method' => 'cash']);
        }
        $bonus = $this->postJson('/api/v1/payroll/pay-components', ['code' => 'BONUS', 'name' => 'Annual bonus', 'kind' => 'earning', 'calculation' => 'manual', 'taxable' => true, 'is_bonus' => true])
            ->assertCreated()->assertJsonPath('data.is_bonus', true)->json('data.uuid');

        $offCycle = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6, 'type' => 'off_cycle'])->assertCreated()->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$offCycle}/inputs", ['employee_uuid' => \App\Models\SelfService\Employee::where('staff_id', 'A1')->value('uuid'), 'pay_component_uuid' => $bonus, 'amount' => 10000])->assertCreated();
        $this->postJson("/api/v1/payroll/runs/{$offCycle}/calculate")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'regular pay run first'));

        $regular = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$regular}/calculate")->assertOk()->assertJsonPath('data.totals.employees', 2);

        // One employee, the bonus only: 9,000 (15% of 60,000) at 5% = 450; 1,000 more at 25% on top of the salary = 250.
        $this->postJson("/api/v1/payroll/runs/{$offCycle}/calculate")->assertOk()
            ->assertJsonPath('data.totals.employees', 1)
            ->assertJsonPath('data.totals.gross_pay', 10000)
            ->assertJsonPath('data.totals.paye', 700);
    }
}
