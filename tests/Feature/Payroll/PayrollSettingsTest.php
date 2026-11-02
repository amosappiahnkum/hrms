<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PayrollSettingsTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
    }

    private function hr()
    {
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'approve-payroll', 'view-payroll']);

        return $hr;
    }

    private function turnOn(string ...$flags): void
    {
        foreach ($flags as $flag) {
            Setting::where('key', "features.payroll.{$flag}")->update(['value' => true]);
        }
        app(SettingService::class)->refreshCache();
    }

    public function test_payroll_is_off_by_default_and_any_part_opens_the_settings(): void
    {
        Sanctum::actingAs($this->hr());
        $this->getJson('/api/v1/payroll/settings')->assertForbidden();

        // Overtime alone is enough: it needs the shared settings too.
        $this->turnOn('overtime');
        $this->getJson('/api/v1/payroll/settings')->assertOk()
            ->assertJsonPath('data.base_currency', 'GHS')
            ->assertJsonPath('data.working_days_per_month', 22)
            ->assertJsonPath('data.overtime_tax_method', 'income')
            ->assertJsonPath('data.features.overtime', true)
            ->assertJsonPath('data.features.enabled', false);
    }

    public function test_only_payroll_configurers_change_settings_and_values_are_checked(): void
    {
        $this->turnOn('enabled');
        Sanctum::actingAs($this->hr());

        $this->putJson('/api/v1/payroll/settings', ['working_days_per_month' => 40, 'overtime_tax_method' => 'flat'])
            ->assertUnprocessable()->assertJsonValidationErrors(['working_days_per_month', 'overtime_tax_method']);
        $this->putJson('/api/v1/payroll/settings', ['base_currency' => 'usd', 'hours_per_day' => 7.5, 'overtime_tax_method' => 'gra_junior'])
            ->assertOk()
            ->assertJsonPath('data.base_currency', 'USD')
            ->assertJsonPath('data.hours_per_day', 7.5)
            ->assertJsonPath('data.overtime_tax_method', 'gra_junior');

        $viewer = $this->userWithRole('staff');
        $viewer->givePermissionTo('view-payroll');
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/payroll/settings')->assertOk();
        $this->putJson('/api/v1/payroll/settings', ['hours_per_day' => 9])->assertForbidden();

        Sanctum::actingAs($this->userWithRole('staff'));
        $this->getJson('/api/v1/payroll/settings')->assertForbidden();
    }
}
