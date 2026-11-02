<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayComponent;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\ExchangeRate;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class ExchangeRatesTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_rates_are_dated_and_the_latest_one_on_a_date_applies(): void
    {
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['configure-payroll']);
        Sanctum::actingAs($hr);

        $uuid = $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'usd', 'effective_date' => '2026-01-01', 'rate' => 15.2])
            ->assertCreated()->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.rate_type', 'standard')->json('data.uuid');
        $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'USD', 'effective_date' => '2026-01-01', 'rate' => 16])
            ->assertUnprocessable()->assertJsonValidationErrors('effective_date');
        // The same date is fine for the other type.
        $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'USD', 'rate_type' => 'basic', 'effective_date' => '2026-01-01', 'rate' => 13])->assertCreated();
        $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'GHS', 'effective_date' => '2026-01-01', 'rate' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'USD', 'effective_date' => '2026-02-01', 'rate' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('rate');
        $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'USD', 'effective_date' => '2026-09-22', 'rate' => 11.3933])->assertCreated();

        $this->assertSame(15.2, ExchangeRate::for('usd', Carbon::parse('2026-09-21')));
        $this->assertSame(11.3933, ExchangeRate::for('USD', Carbon::parse('2026-09-22')));
        $this->assertNull(ExchangeRate::for('USD', Carbon::parse('2025-12-31')));
        $this->assertSame(1.0, ExchangeRate::for('GHS', Carbon::parse('2025-12-31')));
        $this->assertSame(13.0, ExchangeRate::for('USD', Carbon::parse('2026-09-22'), ExchangeRate::BASIC));
        ExchangeRate::where('rate_type', 'basic')->delete();
        // Without a basic rate, basic falls back to the standard one.
        $this->assertSame(11.3933, ExchangeRate::for('USD', Carbon::parse('2026-09-22'), ExchangeRate::BASIC));

        $this->putJson("/api/v1/payroll/exchange-rates/{$uuid}", ['currency' => 'USD', 'effective_date' => '2026-01-01', 'rate' => 15.75])->assertOk();
        $this->getJson('/api/v1/payroll/exchange-rates')->assertJsonPath('data.base_currency', 'GHS')
            ->assertJsonPath('data.rates.1.rate', 15.75)
            ->assertJsonPath('data.rates.0.effective_date', '2026-09-22');
    }

    public function test_a_run_converts_basic_and_allowances_at_their_rates_on_the_rate_date(): void
    {
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        $settings = app(SettingService::class);
        $settings->refreshCache();
        $settings->set('payroll.period_start_day', 21);
        \App\Models\Payroll\StatutoryRateSet::query()->update(['confirmed_at' => now()]);
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['prepare-payroll', 'configure-payroll', 'view-payroll']);
        Sanctum::actingAs($hr);

        $employee = $this->userWithRole('staff')->employee;
        $employee->update(['ssnit_number' => 'C1']);
        $profile = EmployeePayProfile::create(['employee_id' => $employee->id, 'effective_from' => '2026-01-01', 'basic_salary' => 1000, 'currency' => 'USD', 'payment_method' => 'cash']);
        $allowance = PayComponent::create(['code' => 'OFFSHORE_ALW', 'name' => 'Offshore allowance', 'kind' => 'earning', 'calculation' => 'fixed', 'rate' => 100, 'currency' => 'USD', 'recurring' => true, 'prorate' => false, 'taxable' => true]);
        EmployeePayComponent::create(['employee_id' => $employee->id, 'pay_component_id' => $allowance->id, 'effective_from' => '2026-01-01']);
        foreach ([['standard', '2026-09-01', 11], ['standard', '2026-09-22', 11.3933], ['basic', '2026-01-01', 13]] as [$type, $date, $rate]) {
            ExchangeRate::create(['currency' => 'USD', 'rate_type' => $type, 'effective_date' => $date, 'rate' => $rate]);
        }

        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 9])->json('data.uuid');
        $lines = function () use ($run) {
            $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk();

            return PayRun::where('uuid', $run)->first()->payslips()->sole()->lines->keyBy('code');
        };

        // Default: the rate in force on the period's last day (20 Sep), one rate for everything.
        $l = $lines();
        $this->assertSame([11000.0, 1100.0], [(float) $l['BASIC']->amount, (float) $l['OFFSHORE_ALW']->amount]);

        // As on Apave's payslip: rates of the 22nd, basic at its own rate.
        $settings->set('payroll.exchange_rate_day', 22);
        $settings->set('payroll.separate_basic_rate', true);
        $l = $lines();
        $this->assertSame([13000.0, 13.0], [(float) $l['BASIC']->amount, (float) $l['BASIC']->exchange_rate]);
        $this->assertSame([1139.33, 11.3933], [(float) $l['OFFSHORE_ALW']->amount, (float) $l['OFFSHORE_ALW']->exchange_rate]);
        $this->getJson("/api/v1/payroll/runs/{$run}")
            ->assertJsonPath('data.exchange_rates.USD.standard.date', '2026-09-22')
            ->assertJsonPath('data.exchange_rates.USD.basic.rate', 13)
            ->assertJsonPath('data.exchange_rates.USD.basic.date', '2026-01-01');

        // An employee's contract rate wins for their basic.
        $profile->update(['fixed_exchange_rate' => 12.5]);
        $this->assertSame(12500.0, (float) $lines()['BASIC']->amount);

        // Once the run is approved, the rates it used can't change; others can.
        PayRun::where('uuid', $run)->update(['status' => 'approved']);
        $used = ExchangeRate::whereDate('effective_date', '2026-09-22')->first();
        $this->putJson("/api/v1/payroll/exchange-rates/{$used->uuid}", ['currency' => 'USD', 'effective_date' => '2026-09-22', 'rate' => 12])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Payroll · September 2026'));
        $unused = ExchangeRate::whereDate('effective_date', '2026-09-01')->first();
        $this->putJson("/api/v1/payroll/exchange-rates/{$unused->uuid}", ['currency' => 'USD', 'effective_date' => '2026-09-01', 'rate' => 10.9])->assertOk();
    }

    public function test_retired_codes_are_read_as_current_ones_and_a_missing_rate_says_who_uses_it(): void
    {
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        \App\Models\Payroll\StatutoryRateSet::query()->update(['confirmed_at' => now()]);
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['prepare-payroll', 'configure-payroll']);
        Sanctum::actingAs($hr);
        $employee = $this->userWithRole('staff')->employee;

        // GHC (the old cedi code) is saved as the base currency; unknown codes are refused.
        $profile = ['mode' => 'correct', 'basic_salary' => 5000, 'payment_method' => 'cash'];
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $profile + ['currency' => 'GHC'])->assertOk()->assertJsonPath('data.profile.currency', 'GHS');
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $profile + ['currency' => 'XYZ'])->assertStatus(422);

        // A currency without a rate stops the run, saying which and who uses it.
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $profile + ['currency' => 'EUR'])->assertOk();
        $run = $this->postJson('/api/v1/payroll/runs', ['year' => (int) now()->year, 'month' => (int) now()->month])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'no EUR exchange rate') && str_contains($m, "1 employee's pay details"));
    }

    public function test_a_fixed_rate_is_kept_only_for_pay_in_another_currency(): void
    {
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['prepare-payroll', 'configure-payroll']);
        Sanctum::actingAs($hr);
        $employee = $this->userWithRole('staff')->employee;
        $profile = ['mode' => 'correct', 'basic_salary' => 1000, 'payment_method' => 'cash', 'fixed_exchange_rate' => 13];

        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $profile + ['currency' => 'USD'])->assertOk()
            ->assertJsonPath('data.profile.fixed_exchange_rate', 13);
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $profile + ['currency' => 'GHS'])->assertOk()
            ->assertJsonPath('data.profile.fixed_exchange_rate', null);
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", ['fixed_exchange_rate' => 0] + $profile + ['currency' => 'USD'])
            ->assertStatus(422);
    }
}
