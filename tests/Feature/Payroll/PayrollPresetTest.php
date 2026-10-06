<?php

namespace Tests\Feature\Payroll;

use App\Models\Payroll\ApprovalWorkflow;
use App\Models\Payroll\ExchangeRate;
use App\Models\Payroll\OvertimeType;
use App\Models\Payroll\PayComponent;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollPresetTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = database_path('seeders/organizations/preset-test.payroll.json');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_an_unconfirmed_preset_is_refused_and_a_confirmed_one_applies_and_reapplies(): void
    {
        // Starts from the Apave preset, whatever its values are now.
        $preset = json_decode(file_get_contents(database_path('seeders/organizations/abave.payroll.json')), true);
        $preset['confirmed'] = false;
        file_put_contents($this->path, json_encode($preset));
        $this->artisan('payroll:apply-preset preset-test')->assertFailed();

        $preset['confirmed'] = true;
        $preset['exchange_rates'][0]['rate'] = 15.5;
        foreach ($preset['components'] as &$c) {
            $c['rate'] ??= $c['calculation'] === 'manual' ? null : 50;
        }
        file_put_contents($this->path, json_encode($preset));

        $this->artisan('payroll:apply-preset preset-test')->assertSuccessful();
        $this->artisan('payroll:apply-preset preset-test')->assertSuccessful();

        $this->assertTrue(app(SettingService::class)->get('features.payroll.overtime'));
        $this->assertSame(15.5, (float) ExchangeRate::where('currency', 'USD')->value('rate'));
        $this->assertSame(1, PayComponent::where('code', 'OT_WEEKDAY')->count());
        $this->assertSame(4, OvertimeType::count());
        $overtime = ApprovalWorkflow::where('process', 'overtime')->where('is_default', true)->with('steps')->sole();
        $this->assertSame(['supervisor', 'department_head', 'permission'], $overtime->steps->pluck('approver_type.value')->all());
    }
}
