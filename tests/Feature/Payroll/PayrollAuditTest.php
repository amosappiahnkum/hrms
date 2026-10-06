<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PayrollAuditTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_changes_to_pay_and_settings_are_listed_with_who_and_what_without_secrets(): void
    {
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['prepare-payroll', 'configure-payroll']);
        Sanctum::actingAs($hr);
        $employee = $this->userWithRole('staff')->employee;

        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", [
            'mode' => 'correct', 'effective_from' => '2026-01-01', 'basic_salary' => 5000, 'payment_method' => 'bank',
            'bank_name' => 'GCB', 'account_name' => 'X', 'account_number' => '1234567890',
        ])->assertOk();
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", [
            'mode' => 'correct', 'effective_from' => '2026-01-01', 'basic_salary' => 5500, 'payment_method' => 'bank',
            'bank_name' => 'GCB', 'account_name' => 'X', 'account_number' => '1234567890',
        ])->assertOk();
        $this->putJson('/api/v1/payroll/settings', ['working_days_per_month' => 21])->assertOk();

        $res = $this->getJson('/api/v1/payroll/audit')->assertOk();
        $rows = collect($res->json('data.data'));
        $raise = $rows->first(fn ($r) => $r['what'] === 'Pay details' && $r['event'] === 'updated');
        $this->assertSame($hr->name, $raise['by']);
        $this->assertSame(trim(preg_replace('/\s+/', ' ', $employee->name)), $raise['employee']);
        $this->assertContains(['field' => 'basic salary', 'from' => '5000.00', 'to' => '5500.00'], $raise['changes']);
        $this->assertTrue($rows->contains(fn ($r) => $r['what'] === 'Payroll setting' && $r['record'] === 'payroll.working_days_per_month'));
        $this->assertStringNotContainsString('1234567890', $res->getContent());

        $this->getJson('/api/v1/payroll/audit?subject=Setting')->assertOk()->assertJsonPath('data.data.0.what', 'Payroll setting');
        $this->get('/api/v1/payroll/audit/export')->assertOk();
    }
}
