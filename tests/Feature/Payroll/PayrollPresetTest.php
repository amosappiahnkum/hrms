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
        // The FM-07 workflow's second step needs the role to exist.
        file_put_contents($this->path, json_encode($preset));
        $this->artisan('payroll:apply-preset preset-test')->expectsOutputToContain('no role "operations-manager"')->assertFailed();
        $role = new \Spatie\Permission\Models\Role();
        $role->name = 'operations-manager';
        $role->guard_name = 'web';
        $role->uuid = (string) \Illuminate\Support\Str::uuid();
        $role->save();
        $preset['exchange_rates'][0]['rate'] = 15.5;
        foreach ($preset['components'] as &$c) {
            $c['rate'] ??= $c['calculation'] === 'manual' ? null : 50;
        }
        file_put_contents($this->path, json_encode($preset));

        $this->artisan('payroll:apply-preset preset-test')->assertSuccessful();
        $this->artisan('payroll:apply-preset preset-test')->assertSuccessful();

        $this->assertTrue(app(SettingService::class)->get('features.payroll.overtime'));
        $this->assertSame(15.5, (float) ExchangeRate::where(['currency' => 'USD', 'rate_type' => 'standard'])->value('rate'));
        $this->assertSame(13.0, (float) ExchangeRate::where(['currency' => 'USD', 'rate_type' => 'basic'])->value('rate'));
        $this->assertSame(1, PayComponent::where('code', 'OT_WEEKDAY')->count());
        $this->assertSame(4, OvertimeType::count());
        $this->assertSame(['ARD', 'MV25', 'TC'], \App\Models\Payroll\WorkSite::orderBy('code')->pluck('code')->all());
        $this->assertTrue(\App\Models\Payroll\WorkSite::where('code', 'MV25')->value('is_offshore'));
        $this->assertSame('NDT', \App\Models\Payroll\CostCentre::value('code'));
        $overtime = ApprovalWorkflow::where('process', 'overtime')->where('is_default', true)->with('steps')->sole();
        $this->assertSame(['supervisor', 'role'], $overtime->steps->pluck('approver_type.value')->all());
        $this->assertSame(['can_postpone', 'on_client_timesheet'], $overtime->steps[0]->questions);
        $this->assertSame('before', app(SettingService::class)->get('payroll.overtime_mode'));
        $this->assertSame('timesheet', app(SettingService::class)->get('payroll.time_source'));
        $this->assertCount(8, app(\App\Services\Payroll\TimesheetService::class)->columns());
        $this->assertSame(['supervisor', 'role'], ApprovalWorkflow::where('process', 'timesheet')->where('is_default', true)->with('steps')->sole()->steps->pluck('approver_type.value')->all());
    }

    public function test_a_setup_exports_as_a_preset_that_rebuilds_it(): void
    {
        // Set up as Apave (the role its workflow names must exist).
        $role = new \Spatie\Permission\Models\Role(['name' => 'operations-manager', 'guard_name' => 'web']);
        $role->uuid = (string) \Illuminate\Support\Str::uuid();
        $role->save();
        $this->artisan('payroll:apply-preset abave')->assertSuccessful();
        $export = fn () => app(\App\Console\Commands\ExportPayrollPreset::class)->preset('preset-test');
        $before = $export();

        $this->assertTrue($before['features']['payroll.timesheets']);
        $this->assertArrayHasKey('payroll.timesheet_columns', $before['settings']);
        $this->assertContains('OT_WEEKDAY', array_column($before['components'], 'code'));
        $this->assertNotContains('BASIC', array_column($before['components'], 'code')); // system components aren't exported
        $this->assertSame(['operations-manager'], collect($before['workflows'])->firstWhere('process', 'overtime')['steps'][1]['approver_value']);

        // Written as a file (not over an existing one without --force)...
        $this->artisan('payroll:export-preset preset-test')->assertSuccessful();
        $this->artisan('payroll:export-preset preset-test')->assertFailed();
        $this->assertEquals($before['components'], json_decode(file_get_contents($this->path), true)['components']);

        // ...that rebuilds the setup after it's gone.
        OvertimeType::query()->forceDelete();
        PayComponent::where('is_system', false)->forceDelete();
        \App\Models\Payroll\ApprovalWorkflowStep::query()->delete();
        ApprovalWorkflow::query()->forceDelete();
        app(SettingService::class)->set('payroll.period_start_day', 1);
        $this->artisan('payroll:apply-preset preset-test')->assertSuccessful();

        $after = $export();
        unset($before['_note'], $after['_note']);
        $this->assertSame($before, $after);
    }
}
