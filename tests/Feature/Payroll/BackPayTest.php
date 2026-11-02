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

class BackPayTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_a_raise_entered_late_is_paid_once_as_back_pay(): void
    {
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['prepare-payroll']);
        Sanctum::actingAs($hr);
        $employee = $this->userWithRole('staff')->employee;
        $employee->update(['ssnit_number' => 'C1']);
        EmployeePayProfile::create(['employee_id' => $employee->id, 'effective_from' => '2026-01-01', 'basic_salary' => 5000, 'payment_method' => 'cash']);

        // April and May paid at 5,000.
        foreach ([4, 5] as $month) {
            $uuid = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => $month])->json('data.uuid');
            $this->postJson("/api/v1/payroll/runs/{$uuid}/calculate")->assertOk();
            PayRun::where('uuid', $uuid)->update(['status' => PayRunStatus::PAID]);
        }

        // A raise to 5,500 from April, entered in June: June pays 2 × 500 back pay.
        EmployeePayProfile::create(['employee_id' => $employee->id, 'effective_from' => '2026-04-01', 'basic_salary' => 5500, 'payment_method' => 'cash']);
        $june = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$june}/calculate")->assertOk()->assertJsonPath('data.totals.gross_pay', 6500);
        $this->getJson("/api/v1/payroll/runs/{$june}/inputs")->assertJsonPath('data.0.source', 'arrears')->assertJsonPath('data.0.notes', 'Back pay for Apr 2026, May 2026');
        // Recalculating doesn't pay it twice.
        $this->postJson("/api/v1/payroll/runs/{$june}/calculate")->assertOk()->assertJsonPath('data.totals.gross_pay', 6500);

        // Once June is paid, July owes nothing more.
        PayRun::where('uuid', $june)->update(['status' => PayRunStatus::PAID]);
        $july = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 7])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$july}/calculate")->assertOk()->assertJsonPath('data.totals.gross_pay', 5500);
    }
}
