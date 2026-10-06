<?php

namespace Tests\Feature\Payroll;

use App\Enums\Payroll\PayRunStatus;
use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PayrollReportsTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_the_dashboard_compares_runs_and_the_year_adds_up_per_employee(): void
    {
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['prepare-payroll', 'view-payroll']);
        Sanctum::actingAs($hr);

        $this->getJson('/api/v1/payroll/dashboard')->assertOk()->assertJsonPath('data.run', null);

        $staying = $this->userWithRole('staff')->employee;
        $leaving = $this->userWithRole('staff')->employee;
        foreach ([$staying, $leaving] as $e) {
            $e->update(['ssnit_number' => 'C1']);
            EmployeePayProfile::create(['employee_id' => $e->id, 'effective_from' => '2026-01-01', 'basic_salary' => 5000, 'payment_method' => 'cash']);
        }
        $run = function (int $month) {
            $uuid = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => $month])->json('data.uuid');
            $this->postJson("/api/v1/payroll/runs/{$uuid}/calculate")->assertOk();
            PayRun::where('uuid', $uuid)->update(['status' => PayRunStatus::PAID]);
        };
        $run(5);
        $leaving->update(['termination_date' => '2026-05-31']);
        EmployeePayProfile::where('employee_id', $staying->id)->update(['basic_salary' => 6000]);
        $run(6);

        $this->getJson('/api/v1/payroll/dashboard')->assertOk()
            ->assertJsonPath('data.run.name', 'Payroll · June 2026')
            ->assertJsonPath('data.previous.name', 'Payroll · May 2026')
            ->assertJsonPath('data.change.employees', -1)
            ->assertJsonCount(1, 'data.movements.left')
            ->assertJsonPath('data.movements.changed.0.from', 3945.25)
            ->assertJsonCount(2, 'data.trend')
            ->assertJsonPath('data.by_component.0.code', 'BASIC');

        $rows = collect($this->getJson('/api/v1/payroll/year-to-date?year=2026')->assertOk()->json('data.rows'));
        // May 5,000; June 6,000 plus 1,000 back pay for May (the raise was entered on the existing details).
        $this->assertSame(12000.0, (float) $rows->firstWhere('name', trim(preg_replace('/\s+/', ' ', $staying->name)))['gross_pay']);

        foreach (['ytd', 'paye', 'ssnit'] as $report) {
            $this->get("/api/v1/payroll/annual/{$report}?year=2026")->assertOk();
        }
        $this->get('/api/v1/payroll/annual/nope?year=2026')->assertNotFound();
    }
}
